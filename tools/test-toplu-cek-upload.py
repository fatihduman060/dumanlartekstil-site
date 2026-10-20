"""Isolated HTTP upload regression test. Run with PHP_BIN=/path/to/php python3 tools/test-toplu-cek-upload.py."""
import base64, json, os, pathlib, socket, sqlite3, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
with tempfile.TemporaryDirectory() as folder:
    root = pathlib.Path(folder)
    source = (ROOT / 'muhasebe/bootstrap.php').read_text()
    helpers = source[source.index('function ensure_upload_dir('):source.index('function delete_replaced_upload(')]
    bootstrap = '''<?php
const MAX_UPLOAD_BYTES = 10485760;
define('UPLOAD_DIR', __DIR__ . '/uploads');
function redirect($url) { header('Location: /result.php'); exit; }
function require_login() { if (($_SERVER['HTTP_X_AUTH'] ?? '') !== 'yes') { http_response_code(401); exit; } }
function require_write() { if (($_SERVER['HTTP_X_WRITE'] ?? '') !== 'yes') { http_response_code(403); exit; } }
function require_csrf() { if (($_POST['csrf_token'] ?? '') !== 'test') { http_response_code(403); exit; } }
function db() { static $pdo; if (!$pdo) { $pdo = new PDO('sqlite:' . __DIR__ . '/test.sqlite'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return $pdo; }
function now() { return '2026-10-20 12:00:00'; }
function current_user() { return ['id'=>1]; }
function decimal_from_input($v) { return (float)$v; }
function sync_movement_account_transaction($id) {}
function sync_check_account_transaction($id) {}
function log_action(...$args) {}
function audit_action(...$args) {}
function money($v) { return (string)$v; }
function flash($type, $message) { file_put_contents(__DIR__ . '/result.json', json_encode([$type,$message])); }
'''
    (root / 'bootstrap.php').write_text(bootstrap + helpers)
    (root / 'result.php').write_text("<?php readfile(__DIR__ . '/result.json');")
    (root / 'endpoint.php').write_text((ROOT / 'muhasebe/hareket-toplu-cek-senet.php').read_text())
    conn = sqlite3.connect(root / 'test.sqlite')
    conn.execute('CREATE TABLE categories(id INTEGER PRIMARY KEY, name TEXT UNIQUE, type TEXT, created_at TEXT)')
    for table, columns in [('movements', 'cari_id category_id account_id movement_type amount currency movement_date due_date payment_method description document_type document_path document_name document_mime check_id created_by created_at updated_at'), ('checks', 'cari_id movement_id direction status amount issue_date due_date bank_name branch_name check_no drawer description document_path document_name document_mime created_by created_at updated_at')]:
        conn.execute('CREATE TABLE '+table+'(id INTEGER PRIMARY KEY,'+','.join(c+' TEXT' for c in columns.split())+')')
    conn.commit()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    server = subprocess.Popen([PHP, '-d', 'display_errors=0', '-d', 'upload_max_filesize=12M', '-d', 'post_max_size=40M', '-d', 'max_file_uploads=20', '-S', f'127.0.0.1:{port}', '-t', folder], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    def send(files=None, count=3, auth=True, write=True, csrf='test', instrument='cek'):
        fields = [('csrf_token',csrf), ('instrument',instrument), ('movement_type','tahsilat'), ('cari_id','1'), ('movement_date','2026-10-20'), ('currency','TL')]
        for i in range(count):
            fields += [(k+'[]',v) for k,v in [('item_person','Drawer '+str(i)),('item_bank','Bank'),('item_check_no',str(i)),('item_amount','100'),('item_city','İstanbul'),('item_due_date','2026-11-20')]]
        for i in (files or {}): fields.append(('item_has_document_'+str(i),'1'))
        boundary = 'TESTBOUNDARY'
        body = bytearray()
        for key, value in fields:
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        for i, payload in (files or {}).items():
            if payload is None: continue
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="item_document_{i}"; filename="check{i}.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+payload+b'\r\n')
        body.extend(f'--{boundary}--\r\n'.encode())
        req = urllib.request.Request(f'http://127.0.0.1:{port}/endpoint.php', data=body, headers={'Content-Type':'multipart/form-data; boundary='+boundary,'X-Auth':'yes' if auth else 'no','X-Write':'yes' if write else 'no'})
        try:
            with urllib.request.urlopen(req) as response: return response.status, json.load(response)
        except urllib.error.HTTPError as error: return error.code, None
    def state():
        return conn.execute('SELECT COUNT(*) FROM checks').fetchone()[0], len(list((root/'uploads').glob('**/*.*')))
    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZW0AAAAASUVORK5CYII=')
    try:
        for attempt in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port), timeout=.1): break
            except OSError: time.sleep(.1)
        assert send({0:png,2:png})[1][0] == 'success'
        linked = conn.execute('SELECT c.document_path,m.document_path,c.document_name FROM checks c JOIN movements m ON m.id=c.movement_id ORDER BY c.id').fetchall()
        assert linked[0][0] == linked[0][1] and linked[0][2] == 'check0.png'
        assert linked[1] == (None,None,None)
        assert linked[2][0] == linked[2][1] and linked[2][2] == 'check2.png' and linked[0][0] != linked[2][0]
        baseline = state()
        for files in [{0:png,1:b'<?php echo "bad";'}, {0:png,1:png+b'x'*10485760}, {0:png,2:None}, {0:png,1:b''}]:
            assert send(files)[1][0] == 'error'
            assert state() == baseline, 'Failed batch left rows or files behind'
        assert send({i:png for i in range(24)},count=24)[1][0] == 'error'
        assert state() == baseline, 'Truncated uploads must roll back'
        for options in [dict(auth=False),dict(write=False),dict(csrf='wrong')]:
            assert send({0:png},**options)[0] in (401,403)
            assert state() == baseline
        assert send(instrument='senet')[1][0] == 'success'
        assert send()[1][0] == 'success'
        print('PASS: per-row links, optional files, MIME/size/empty-file rejection, rollback cleanup, 24-file truncation, login/write/CSRF, senet and no-file batches')
    finally:
        server.terminate(); server.wait()
