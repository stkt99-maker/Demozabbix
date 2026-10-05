<?php
/*
** Zabbix
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


require_once dirname(__FILE__).'/include/classes/user/CWebUser.php';

require_once dirname(__FILE__).'/include/config.inc.php';

$page['file'] = 'brand_admin.php';

// Super admin only.
if (!CWebUser::isLoggedIn() || CWebUser::isGuest()) {
	redirect('index.php');
}

if (CWebUser::$data['type'] != USER_TYPE_SUPER_ADMIN) {
	redirect('index.php');
}

define('BRAND_IMG_DIR', dirname(__FILE__).'/local/img');
define('BRAND_CONF_FILE', dirname(__FILE__).'/local/conf/brand.conf.php');

// The logo is served under the frontend root, e.g. "local/img/brand-logo-<time>.png".
const BRAND_LOGO_URL_PREFIX = 'local/img/';

$allowed_mimes = [
	'image/png' => 'png',
	'image/jpeg' => 'jpg',
	'image/svg+xml' => 'svg',
	'text/xml' => 'svg',
	'text/plain' => 'svg'
];

$messages = [];

function brandLogoUrl(): string {
	foreach (['png', 'jpg', 'svg'] as $ext) {
		foreach (glob(BRAND_IMG_DIR.'/brand-logo-*.'.$ext) ?: [] as $file) {
			return BRAND_LOGO_URL_PREFIX.basename($file);
		}
	}

	return '';
}

if (hasRequest('action')) {
	if (!CCsrfTokenHelper::check(getRequest('csrf_token', ''), 'brand_admin.php')) {
		$messages[] = ['type' => 'error', 'text' => 'CSRF token ไม่ถูกต้อง รีเฟรชหน้าแล้วลองใหม่'];
	}
	elseif (getRequest('action') === 'upload') {
		$file = $_FILES['logo'] ?? null;

		if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
			$messages[] = ['type' => 'error', 'text' => 'อัปโหลดไม่สำเร็จ ลองเลือกไฟล์ใหม่'];
		}
		elseif ($file['size'] > 2 * 1024 * 1024) {
			$messages[] = ['type' => 'error', 'text' => 'ไฟล์ใหญ่เกิน 2 MB'];
		}
		else {
			$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

			if (!array_key_exists($mime, $allowed_mimes)
					|| ($allowed_mimes[$mime] === 'svg' && !preg_match('/\.svg$/i', $file['name']))) {
				$messages[] = ['type' => 'error', 'text' => 'รองรับเฉพาะไฟล์ PNG, JPG หรือ SVG'];
			}
			else {
				$ext = $allowed_mimes[$mime];

				foreach (glob(BRAND_IMG_DIR.'/brand-logo*.*') ?: [] as $old) {
					@unlink($old);
				}

				$target = BRAND_IMG_DIR.'/brand-logo-'.time().'.'.$ext;
				$logo_url = BRAND_LOGO_URL_PREFIX.basename($target);

				if (move_uploaded_file($file['tmp_name'], $target)) {
					@chmod($target, 0644);

					$conf = "<?php return [\n"
						."\t'BRAND_LOGO' => '".$logo_url."',\n"
						."\t'BRAND_LOGO_SIDEBAR' => '".$logo_url."',\n"
						."\t'BRAND_LOGO_SIDEBAR_COMPACT' => '".$logo_url."'\n"
						."];\n";

					if (@file_put_contents(BRAND_CONF_FILE, $conf) !== false) {
						$messages[] = ['type' => 'info', 'text' => 'บันทึกโลโก้ใหม่เรียบร้อย รีเฟรชหน้าเว็บเพื่อดูผล'];
					}
					else {
						$messages[] = ['type' => 'error', 'text' => 'เขียนไฟล์ brand.conf.php ไม่สำเร็จ (ตรวจสิทธิ์โฟลเดอร์ local/conf)'];
					}
				}
				else {
					$messages[] = ['type' => 'error', 'text' => 'ย้ายไฟล์ที่อัปโหลดไม่สำเร็จ (ตรวจสิทธิ์โฟลเดอร์ local/img)'];
				}
			}
		}
	}
	elseif (getRequest('action') === 'reset') {
		foreach (glob(BRAND_IMG_DIR.'/brand-logo*.*') ?: [] as $old) {
			@unlink($old);
		}

		if (!file_exists(BRAND_CONF_FILE) || @unlink(BRAND_CONF_FILE)) {
			$messages[] = ['type' => 'info', 'text' => 'กลับไปใช้โลโก้ Zabbix เริ่มต้นแล้ว'];
		}
		else {
			$messages[] = ['type' => 'error', 'text' => 'ลบไฟล์ brand.conf.php ไม่สำเร็จ'];
		}
	}
}

$custom_logo = brandLogoUrl();
$csrf_token = CCsrfTokenHelper::get('brand_admin.php');

session_write_close();

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="th">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>โลโก้ (Branding) — Zabbix</title>
	<link rel="stylesheet" type="text/css" href="assets/styles/modern-theme.css">
	<style>
		body { background: #ebeef0; margin: 0; font-family: Sarabun, Arial, Tahoma, sans-serif; }
		.brand-admin { max-width: 680px; margin: 32px auto 48px; color: #1f2c33; font-size: 14px; }
		.brand-admin .card { background: #fff; border: 1px solid #dfe4e7; border-radius: 10px;
			box-shadow: 0 1px 3px rgba(31, 44, 51, .06); padding: 22px 26px; margin-bottom: 16px; }
		.brand-admin h1 { font-size: 20px; margin: 0 0 4px; }
		.brand-admin .sub { color: #76828d; margin: 0; }
		.brand-admin .section-title { font-size: 12px; font-weight: bold; letter-spacing: .06em;
			text-transform: uppercase; color: #76828d; margin: 0 0 12px; }
		.brand-admin .msg { padding: 9px 14px; border-radius: 6px; margin: 0 0 14px; font-size: 13.5px; }
		.brand-admin .msg-info { background: #e5f3e5; color: #1d5c1d; border: 1px solid #bfe0bf; }
		.brand-admin .msg-error { background: #fbeaea; color: #8a1f1f; border: 1px solid #f0c4c4; }
		.brand-admin .preview-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
		@media (max-width: 560px) { .brand-admin .preview-grid { grid-template-columns: 1fr; } }
		.brand-admin .preview { border-radius: 8px; padding: 18px 12px 14px; text-align: center;
			display: flex; flex-direction: column; align-items: center; gap: 10px; }
		.brand-admin .preview-dark { background: #2b2f35; }
		.brand-admin .preview-light { background: #f3f5f6; border: 1px solid #e2e7ea; }
		.brand-admin .preview img { max-width: 200px; max-height: 64px; width: auto; height: auto;
			object-fit: contain; }
		.brand-admin .preview-label { font-size: 11px; color: #9aa5ad; }
		.brand-admin .preview-dark .preview-label { color: #8a97a0; }
		.brand-admin .default-mark { color: #d40000; font-weight: bold; font-size: 20px;
			font-family: Arial, sans-serif; letter-spacing: 1px; }
		.brand-admin .preview-dark .default-mark { color: #fff; }
		.brand-admin .dropzone { display: flex; flex-direction: column; align-items: center; gap: 4px;
			border: 2px dashed #b6c2ca; border-radius: 8px; padding: 22px 16px; cursor: pointer;
			transition: border-color .15s, background-color .15s; background: #fafbfc; }
		.brand-admin .dropzone:hover, .brand-admin .dropzone.is-dragover { border-color: #1e87e3;
			background: #f0f7fd; }
		.brand-admin .dropzone .dz-icon { font-size: 22px; color: #1e87e3; }
		.brand-admin .dropzone .dz-main { font-weight: bold; }
		.brand-admin .dropzone .dz-hint { font-size: 12px; color: #76828d; }
		.brand-admin .file-name { font-size: 13px; color: #1f2c33; margin-top: 10px; }
		.brand-admin .file-name.empty { color: #9aa5ad; }
		.brand-admin .actions { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
		.brand-admin .btn { border: 0; border-radius: 6px; padding: 9px 20px; cursor: pointer;
			font-size: 14px; font-family: inherit; transition: filter .15s, background-color .15s; }
		.brand-admin .btn:hover { filter: brightness(.92); }
		.brand-admin .btn:disabled { opacity: .45; cursor: not-allowed; }
		.brand-admin .btn-save { background: #1e87e3; color: #fff; }
		.brand-admin .btn-reset { background: #fff; color: #8a1f1f; border: 1px solid #e0b4b4; }
		.brand-admin .divider { border: 0; border-top: 1px solid #e8ecef; margin: 18px 0; }
		.brand-admin .back { display: inline-block; margin-top: 4px; color: #1e87e3;
			text-decoration: none; }
		.brand-admin .back:hover { text-decoration: underline; }
	</style>
</head>
<body>
<div class="brand-admin">
	<div class="card">
		<h1>โลโก้ (Branding)</h1>
		<p class="sub">เปลี่ยนโลโก้บนแถบข้าง ไอคอนยุบแถบข้าง และหน้าล็อกอิน — ใช้รูปเดียวกันทุกจุด</p>
	</div>

<?php foreach ($messages as $message): ?>
	<div class="msg msg-<?= $message['type'] === 'error' ? 'error' : 'info' ?>"><?= htmlspecialchars($message['text'], ENT_QUOTES) ?></div>
<?php endforeach; ?>

	<div class="card">
		<p class="section-title">รูปโลโก้ปัจจุบัน</p>
		<div class="preview-grid">
			<div class="preview preview-dark">
				<?php if ($custom_logo !== ''): ?>
					<img src="<?= htmlspecialchars($custom_logo, ENT_QUOTES) ?>" alt="โลโก้ที่ตั้งไว้">
				<?php else: ?>
					<span class="default-mark">ZABBIX</span>
				<?php endif; ?>
				<span class="preview-label">แถบข้าง (พื้นเข้ม)</span>
			</div>
			<div class="preview preview-light">
				<?php if ($custom_logo !== ''): ?>
					<img src="<?= htmlspecialchars($custom_logo, ENT_QUOTES) ?>" alt="โลโก้ที่ตั้งไว้">
				<?php else: ?>
					<span class="default-mark">ZABBIX</span>
				<?php endif; ?>
				<span class="preview-label">หน้าล็อกอิน (พื้นสว่าง)</span>
			</div>
		</div>
	</div>

	<div class="card">
		<p class="section-title">อัปโหลดรูปใหม่</p>
		<form method="post" enctype="multipart/form-data">
			<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
			<input type="hidden" name="action" value="upload">
			<label class="dropzone" id="dropzone" for="logo-input">
				<span class="dz-icon">🖼️</span>
				<span class="dz-main">คลิกเลือกไฟล์ หรือลากรูปมาวางที่นี่</span>
				<span class="dz-hint">PNG / JPG / SVG &middot; ไม่เกิน 2 MB &middot; แนะนำพื้นหลังโปร่งใส</span>
			</label>
			<input type="file" name="logo" id="logo-input" accept=".png,.jpg,.jpeg,.svg" required hidden>
			<div class="file-name empty" id="file-name">ยังไม่ได้เลือกไฟล์</div>
			<div class="actions">
				<button type="submit" class="btn btn-save" id="btn-save" disabled>บันทึกโลโก้</button>
			</div>
		</form>

		<hr class="divider">

		<form method="post">
			<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
			<input type="hidden" name="action" value="reset">
			<button type="submit" class="btn btn-reset">รีเซ็ตเป็นโลโก้ Zabbix เริ่มต้น</button>
		</form>
	</div>

	<a class="back" href="zabbix.php">← กลับหน้าหลัก</a>
</div>

<script>
	const input = document.getElementById('logo-input');
	const fileName = document.getElementById('file-name');
	const dropzone = document.getElementById('dropzone');
	const btnSave = document.getElementById('btn-save');

	input.addEventListener('change', () => {
		const has = input.files.length > 0;
		fileName.textContent = has ? input.files[0].name : 'ยังไม่ได้เลือกไฟล์';
		fileName.classList.toggle('empty', !has);
		btnSave.disabled = !has;
	});

	['dragover', 'dragenter'].forEach(ev => dropzone.addEventListener(ev, e => {
		e.preventDefault();
		dropzone.classList.add('is-dragover');
	}));

	['dragleave', 'drop'].forEach(ev => dropzone.addEventListener(ev, e => {
		e.preventDefault();
		dropzone.classList.remove('is-dragover');
	}));

	dropzone.addEventListener('drop', e => {
		if (e.dataTransfer.files.length > 0) {
			input.files = e.dataTransfer.files;
			input.dispatchEvent(new Event('change'));
		}
	});
</script>
</body>
</html>
