"""Real HTTP regression for MessageService defaults; no schema or auth overlays."""
import importlib.util
import json
import os
import pathlib
import subprocess
import tempfile
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('integration', ROOT / 'tests/phase3ad-http.py')
integration = importlib.util.module_from_spec(spec)
spec.loader.exec_module(integration)
h = integration.h


def main():
    baseline = os.environ.get('PHASE3AD_MESSAGE_CONTROL') == '1'
    target = pathlib.Path(os.environ.get('PHASE3AD_MESSAGE_EVIDENCE', 'phase3ad-raw/message-compatibility.json'))
    target.parent.mkdir(parents=True, exist_ok=True)
    out = {'head': h.git('rev-parse', 'HEAD'), 'tree': h.git('rev-parse', 'HEAD^{tree}'),
           'working_diff': h.git('diff', 'HEAD'), 'baseline_control': baseline,
           'source_sha256': h.digest((ROOT / 'services/MessageService.php').read_bytes()),
           'otp_sha256': h.digest((ROOT / 'security/forgot-password/OtpService.php').read_bytes()),
           'cases': [], 'status': 'FAIL', 'migration_sha256': h.digest((ROOT / 'database/migrations/068_message_code_poll_types.sql').read_bytes()) if (ROOT / 'database/migrations/068_message_code_poll_types.sql').exists() else None}
    with tempfile.TemporaryDirectory(prefix='phase3ad-message-') as temp:
        o = h.Owned(pathlib.Path(temp), 'UTC', '+00:00')
        o.env['CENTRIFUGO_ENABLED'] = os.environ.get('PHASE3AD_TEST_OUTBOX', 'false')
        out['outbox_enabled'] = o.env['CENTRIFUGO_ENABLED']
        try:
            o.start()
            actors = [o.registered() for _ in range(4)]
            fixture = integration.runphp(o, 'phase3ad-fixture.php', {'users': [c.uid for c, d in actors]})
            student, peer, facilitator, outsider = [c for c, d in actors]
            for c, d in actors:
                c.csrf()
            channel = fixture['channels']['general']
            cases = [('omitted', {}, 'text'), ('null', {'content_type': None}, 'text')]
            cases += [(kind, {'content_type': kind}, kind) for kind in ['text', 'image', 'file', 'code']]
            cases += [('invalid', {'content_type': 'unsupported'}, 'text'),
                      ('array', {'content_type': []}, 'text'),
                      ('poll', {'content_type': 'poll', 'poll_options': ['A', 'B']}, 'poll')]
            for name, fields, expected_type in cases:
                payload = {'channel_id': channel, 'content': 'Phase3AD default ' + name} | fields
                r = student.req('/API/chat/send-message.php', 'POST', payload)
                # The pinned PR100 base fails code/poll; the migrated candidate must pass.
                expected_status = 500 if baseline and name in ['code', 'poll'] else 201
                h.check(r['status'] == expected_status, name + ' HTTP ' + str(r['status']))
                if expected_status == 201:
                    message = r['json']['message']
                    h.check(message['content_type'] == expected_type, name + ' returned type')
                    history = peer.req('/API/chat/get-messages.php?channel_id=' + str(channel))
                    h.check(history['status'] == 200, 'history HTTP')
                    # The actual read API must expose the stored type, not only the send response.
                    h.check(any(m.get('id') == message['id'] and m.get('content_type') == expected_type
                                for m in history['json']['messages']), name + ' persisted type')
                    if name == 'poll':
                        poll = message['poll']
                        h.check(poll['total_votes'] == 0 and len(poll['options']) == 2, 'poll options persisted')
                        vote = peer.req('/API/chat/vote-poll.php', 'POST', {'poll_id': poll['id'], 'option_id': poll['options'][0]['id']})
                        h.check(vote['status'] == 200, 'authorized poll vote')
                        denied = outsider.req('/API/chat/vote-poll.php', 'POST', {'poll_id': poll['id'], 'option_id': poll['options'][0]['id']})
                        h.check(denied['status'] == 403 and denied['before']['business'] == denied['after']['business'], 'outsider vote denied')
                        after_vote = peer.req('/API/chat/get-messages.php?channel_id=' + str(channel))
                        saved = next(m for m in after_vote['json']['messages'] if m['id'] == message['id'])
                        h.check(saved['poll']['total_votes'] == 1, 'vote persisted and retrieved')
                else:
                    h.check(r['before']['business'] == r['after']['business'], 'failed control mutated business data')
                out['cases'].append({'name': name, 'http_status': r['status'],
                                     'expected_type': expected_type, 'status': 'PASS',
                                     'functional_status': 'FAIL' if expected_status == 500 else 'PASS',
                                     'defect_reproduced': expected_status == 500})
            for name, actor, payload, csrf in [
                ('csrf', student, {'channel_id': channel, 'content': 'denied'}, 'invalid'),
                ('outsider', outsider, {'channel_id': channel, 'content': 'denied'}, 'valid'),
                ('private', peer, {'channel_id': fixture['channels']['private'], 'content': 'denied'}, 'valid'),
                ('announcement', student, {'channel_id': fixture['channels']['announcements'], 'content': 'denied'}, 'valid')]:
                r = actor.req('/API/chat/send-message.php', 'POST', payload, csrf=csrf)
                h.check(r['status'] == 403, name + ' denied')
                h.check(r['before']['business'] == r['after']['business'], name + ' changed business rows')
                out['cases'].append({'name': name, 'http_status': 403, 'status': 'PASS'})
            r = student.req('/API/chat/send-message.php', 'POST', {'channel_id': channel, 'content': ''})
            h.check(r['status'] == 400 and r['before']['business'] == r['after']['business'], 'empty content remains rejected')
            out['cases'].append({'name': 'empty', 'http_status': 400, 'status': 'PASS'})
            if not baseline:
                for name, fields in [
                    ('one-option', {'poll_options': ['A']}),
                    ('seven-options', {'poll_options': list('ABCDEFG')}),
                    ('blank-options', {'poll_options': [' ', '']}),
                    ('options-scalar', {'poll_options': 'AB'}),
                    ('options-nested', {'poll_options': [['A'], 'B']}),
                    ('question-object', {'poll_question': {}, 'poll_options': ['A', 'B']})]:
                    request = {'channel_id': channel, 'content': 'Malformed poll', 'content_type': 'poll'} | fields
                    r = student.req('/API/chat/send-message.php', 'POST', request)
                    h.check(r['status'] == 400 and r['before']['business'] == r['after']['business'], name + ' safely rejected')
                    h.check('SQLSTATE' not in json.dumps(r['json']), 'no SQL diagnostics')
                    out['cases'].append({'name': name, 'http_status': 400, 'status': 'PASS'})
                for name,payload in [('scalar-body','invalid'),('array-content',{'channel_id':channel,'content':[]})]:
                    r=student.req('/API/chat/send-message.php','POST',payload)
                    h.check(r['status']==400 and r['before']['business']==r['after']['business'],name+' rejected')
                    h.check('SQLSTATE'not in json.dumps(r['json']),'no diagnostics')
                    out['cases'].append({'name':name,'http_status':400,'status':'PASS'})
                private = student.req('/API/chat/send-message.php','POST',{'channel_id':fixture['channels']['private'],'content':'Private poll','content_type':'poll','poll_options':['A','B']})
                h.check(private['status']==201,'private poll created')
                poll=private['json']['message']['poll'];payload={'poll_id':poll['id'],'option_id':poll['options'][0]['id']}
                hidden=peer.req('/API/chat/get-messages.php?channel_id='+str(fixture['channels']['private']))
                h.check(hidden['status']==403,'private poll history denied')
                out['cases'].append({'name':'private-poll-history','http_status':403,'status':'PASS'})
                for name,actor,csrf in [('private-poll-parent-member',peer,'valid'),('private-poll-outsider',outsider,'valid'),('private-poll-csrf',student,'invalid')]:
                    denied=actor.req('/API/chat/vote-poll.php','POST',payload,csrf=csrf)
                    h.check(denied['status']==403 and denied['before']['business']==denied['after']['business'],name+' denied')
                    out['cases'].append({'name':name,'http_status':403,'status':'PASS'})
                own=student.req('/API/chat/vote-poll.php','POST',payload)
                h.check(own['status']==200 and own['json']['total_votes']==1,'private poll member vote')
                out['cases'].append({'name':'private-poll-authorized-member','http_status':200,'status':'PASS'})
                before = integration.runphp(o, 'phase3ad-message-control.php', {'action': 'inspect'})
                integration.runphp(o, 'phase3ad-message-control.php', {'action': 'inject'})
                try:
                    failed = student.req('/API/chat/send-message.php', 'POST', {'channel_id': channel, 'content': 'Failed atomic poll', 'content_type': 'poll', 'poll_options': ['A', 'B']})
                    h.check(failed['status'] == 500 and failed['json'] == {'error': 'Unable to send message'}, 'safe database failure')
                finally:
                    integration.runphp(o, 'phase3ad-message-control.php', {'action': 'release'})
                after = integration.runphp(o, 'phase3ad-message-control.php', {'action': 'inspect'})
                h.check(before['counts'] == after['counts'] and before['business'] == after['business'], 'poll transaction rollback')
                out['poll_rollback'] = {'status': 'PASS', 'before': before, 'after': after}
                command = [o.php, '-d', 'auto_prepend_file=' + str(ROOT / 'tests/phase3w-migration-bootstrap.php'), str(ROOT / 'database/migrate.php'), '--force=068_message_code_poll_types.sql']
                repeat = subprocess.run(command, env=o.env, capture_output=True, text=True, timeout=60)
                h.check(repeat.returncode == 0, 'repeat migration')
                repeated = integration.runphp(o, 'phase3ad-message-control.php', {'action': 'inspect'})
                h.check(after == repeated, 'repeat migration preserves rows and column shape')
                out['migration_shapes'] = integration.runphp(o, 'phase3ad-enum-migration.php', {})
                out['migration_reapply'] = {'status': 'PASS', 'command': command, 'exit_code': repeat.returncode, 'stdout': repeat.stdout, 'stderr': repeat.stderr}
            out['suites'] = []
            suite_orders = [('default', []), ('reverse', ['--order-by=reverse']), ('random-31028', ['--order-by=random', '--random-order-seed=31028'])]
            if os.environ.get('PHASE3AD_MESSAGE_HTTP_ONLY')=='1':
                out['suite_status']='NOT RUN in outbox-only supplementary scenario'
                suite_orders=[]
            for label, order in suite_orders:
                drop = '$d=new PDO(getenv("CLASSROOM_DSN"),"root","");$d->exec("SET FOREIGN_KEY_CHECKS=0");foreach($d->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)as$t)$d->exec("DROP TABLE `".str_replace("`","``",$t)."`");'
                subprocess.run([o.php, '-r', drop], env=o.env, check=True, capture_output=True)
                schema = subprocess.run([o.php, str(ROOT / 'tests/phase3w-schema.php')], env=o.env | {'PHASE3W_FULL_PHPUNIT': '0'},
                                        capture_output=True, text=True, timeout=120)
                h.check(schema.returncode == 0 and json.loads(schema.stdout)['full_migration_status'] == 'PASS', 'suite migration')
                junit = o.p / ('junit-' + label + '.xml')
                command = [o.php, '-d', 'auto_prepend_file=' + str(ROOT / 'tests/phase3w-phpunit-bootstrap.php'),
                           '-d', 'session.save_path=/tmp', '-d', 'output_buffering=4096', str(ROOT / 'vendor/bin/phpunit'),
                           '--log-junit', str(junit), *order]
                run = subprocess.run(command, cwd=ROOT, env=o.env | {'PHASE3W_SUITE_ROOT': str(ROOT)},
                                     capture_output=True, text=True, timeout=180)
                xml = ET.fromstring(junit.read_text())
                counts = xml.find('testsuite').attrib
                out['suites'].append({'label': label, 'command': command, 'exit_code': run.returncode,
                                      'stdout': run.stdout, 'stderr': run.stderr, 'junit': junit.read_text(),
                                      'schema': json.loads(schema.stdout), 'counts': counts})
                h.check(run.returncode == 0 and int(counts['tests']) == 60 and int(counts['assertions']) == 280
                        and all(int(counts[k]) == 0 for k in ['errors', 'failures', 'skipped']), 'full suite ' + label)
            out['status'] = 'PASS'
        except Exception as error:
            out['error'] = o.scrub_text(str(error))
        finally:
            o.stop()
            out.update({'requests': o.records, 'controls': o.controls,
                        'migration': getattr(o, 'migration', None),
                        'logs': {p.name: o.scrub_text(p.read_text()) for p in
                                 [o.p / 'php-errors.log', o.p / 'fpm.log', o.p / 'database.log'] if p.exists()}})
            target.write_text(json.dumps(out, indent=2) + '\n')
    print(json.dumps({'status': out['status'], 'cases': len(out['cases']), 'requests': len(out['requests']),
                      'baseline_control': baseline, 'error': out.get('error')}))
    h.check(out['status'] == 'PASS', 'message-default regression')


if __name__ == '__main__':
    main()
