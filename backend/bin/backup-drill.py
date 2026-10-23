#!/usr/bin/env python3
"""Local PostgreSQL backup and isolated restore rehearsal; Python standard library only."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time
from datetime import datetime, timezone
from uuid import uuid4

ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ['docker', 'compose', '-f', str(ROOT / 'docker-compose.yml'), 'exec', '-T', 'postgres']
SOURCE = 'bahitung'
USER = 'bahitung'


def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def psql_args(database):
    return COMPOSE + ['psql', '-X', '-qAt', '-v', 'ON_ERROR_STOP=1', '-U', USER, '-d', database]


def sql(database, statement):
    return run(psql_args(database), input=statement, text=True, capture_output=True).stdout.strip()


def fingerprint(database, tables, snapshot=None):
    statements = ['BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;']
    if snapshot:
        statements.append("SET TRANSACTION SNAPSHOT '" + snapshot + "';")
    for table in tables:
        if not re.fullmatch(r'[a-z_][a-z_0-9]*', table):
            raise RuntimeError('Unsupported table identifier')
        # Only counts and digests leave PostgreSQL, never account/financial contents.
        statements.append(f"SELECT '{table}', count(*), md5(COALESCE(string_agg(digest, '' ORDER BY digest COLLATE \"C\"), '')) FROM (SELECT md5(row_to_json(t)::text) AS digest FROM public.\"{table}\" t) rows;")
    statements.append('COMMIT;')
    return sql(database, '\n'.join(statements))


def main():
    os.umask(0o077)
    started = time.monotonic()
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    identifier = stamp.lower() + '_' + uuid4().hex[:8]
    target = 'bahitung_restore_drill_' + identifier
    directory = ROOT / 'backend/storage/app/private/backups' / identifier
    directory.mkdir(parents=True, mode=0o700)
    archive = directory / 'database.dump'
    partial = directory / 'database.dump.partial'
    report = {'source': SOURCE, 'created_at_utc': stamp, 'restore_database': target, 'status': 'incomplete'}
    holder = None
    created = False
    success = False
    try:
        holder = subprocess.Popen(psql_args(SOURCE), stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        holder.stdin.write('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;\nSELECT pg_export_snapshot();\n')
        holder.stdin.flush()
        snapshot = holder.stdout.readline().strip()
        if not re.fullmatch(r'[0-9A-Fa-f-]+', snapshot):
            raise RuntimeError('Could not establish consistent database snapshot')
        tables = sql(SOURCE, "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename;").splitlines()
        baseline = fingerprint(SOURCE, tables, snapshot)
        with partial.open('xb') as stream:
            run(COMPOSE + ['pg_dump', '-U', USER, '-d', SOURCE, '--format=custom', '--no-owner', '--no-acl', '--snapshot=' + snapshot], stdout=stream, stderr=subprocess.PIPE)
        partial.rename(archive)
        holder.stdin.write('ROLLBACK;\n\\q\n')
        holder.stdin.flush()
        holder.communicate(timeout=15)
        holder = None
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        (directory / 'database.dump.sha256').write_text(digest + '  database.dump\n')
        (directory / 'tables.snapshot.txt').write_text(baseline + '\n')
        report.update(sha256=digest, bytes=archive.stat().st_size, tables=len(tables), snapshot=snapshot)
        # Unique database only. Never restore over or drop an existing database.
        run(COMPOSE + ['createdb', '-U', USER, '--template=template0', target], capture_output=True)
        created = True
        with archive.open('rb') as stream:
            run(COMPOSE + ['pg_restore', '-U', USER, '-d', target, '--no-owner', '--no-acl', '--exit-on-error', '--single-transaction'], stdin=stream, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        restored = fingerprint(target, tables)
        (directory / 'tables.restored.txt').write_text(restored + '\n')
        if restored != baseline:
            raise RuntimeError('Restored table fingerprints differ from the backup snapshot')
        # Check the actual application commands against the restored database.
        env = {**os.environ, 'DB_DATABASE': target, 'DB_URL': '', 'DB_CONNECTION': 'pgsql', 'DB_HOST': '127.0.0.1', 'DB_PORT': '5433', 'APP_ENV': 'testing'}
        if (ROOT / 'backend/bootstrap/cache/config.php').exists():
            raise RuntimeError('Cached Laravel config could override restore database; clear it before this rehearsal')
        checks = []
        for command in ['wallets:reconcile', 'goals:reconcile']:
            result = run([str(ROOT / 'backend/bin/php'), 'artisan', command], cwd=ROOT / 'backend', env=env, text=True, capture_output=True)
            checks.append(result.stdout.strip())
        # Restored ledger and audit triggers must remain enabled.
        triggers = sql(target, "SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgenabled <> 'D';")
        source_triggers = sql(SOURCE, "SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgenabled <> 'D';")
        if triggers != source_triggers or int(triggers) == 0:
            raise RuntimeError('Restored application trigger count mismatch')
        protected = []
        for table in ['balance_movements', 'audit_logs', 'idempotency_receipts', 'transfer_details', 'operation_reversals']:
            if sql(target, f'SELECT EXISTS(SELECT 1 FROM {table});') == 't':
                key = 'operation_id' if table in ['transfer_details', 'operation_reversals'] else 'id'
                # A no-op UPDATE on the restored copy must still be rejected.
                sql(target, f"""DO $$ BEGIN
                    BEGIN
                        UPDATE {table} SET {key}={key} WHERE ctid=(SELECT ctid FROM {table} LIMIT 1);
                        RAISE EXCEPTION 'Append-only protection missing';
                    EXCEPTION WHEN check_violation THEN NULL;
                    END;
                END $$;""")
                protected.append(table)
        report.update(status='passed', table_fingerprints_match=True, enabled_application_triggers=int(triggers), append_only_update_rejected=protected, reconciliation=checks)
        success = True
    finally:
        if holder:
            holder.communicate('ROLLBACK;\n\\q\n', timeout=15)
        if created and success:
            run(COMPOSE + ['dropdb', '-U', USER, target], capture_output=True)
            report['restore_database_removed'] = True
        elif created:
            report['restore_database_removed'] = False
        report['elapsed_seconds'] = round(time.monotonic() - started, 2)
        (directory / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
        print('Backup directory:', directory)
        print('Result:', report['status'])
        if created and not success:
            print('Restore database retained for investigation:', target)
    print('Snapshot table fingerprints match; wallet/goal reconciliation passed.')
    print('Main database unchanged. Temporary restore database removed.')


if __name__ == '__main__':
    main()
