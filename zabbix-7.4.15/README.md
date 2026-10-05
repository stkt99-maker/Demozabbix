![Zabbix logo](misc/images/docs/zabbix_logo.svg?raw=true)

# Zabbix 7.4.15 — คู่มือติดตั้งจากซอร์สโค้ด

repo นี้เก็บซอร์สโค้ด Zabbix 7.4.15 ครบทั้งต้น พร้อมการแก้ไขของเดโมที่ใช้งานจริง — แปลไทย, สวิตช์สลับภาษา EN/TH และธีม Modern ฟอนต์ Sarabun คู่มือนี้เรียบเรียงจากการติดตั้งจริงบน Ubuntu 26.04 LTS (2 cores, RAM 3.3 GB) ทุกอย่างอยู่บนเครื่องเดียว

| ส่วนประกอบ | ที่มา | ตำแหน่งติดตั้ง |
|---|---|---|
| Zabbix Server + Agent | คอมไพล์จากซอร์ส | `/usr/local/sbin` |
| ไฟล์คอนฟิก | มาพร้อม `make install` | `/usr/local/etc` |
| ฐานข้อมูล | PostgreSQL ในเครื่อง | database `zabbix` |
| Frontend | โฟลเดอร์ `ui/` จากซอร์ส | `/usr/share/zabbix` |
| เว็บเซิร์ฟเวอร์ | Nginx + PHP 8.5-FPM | พอร์ต 80 |

## 1. แพ็กเกจที่ต้องติดตั้งก่อน

```bash
sudo apt update
sudo apt install -y build-essential pkg-config \
  libpcre2-dev libevent-dev libssl-dev libpq-dev \
  libsnmp-dev libcurl4-openssl-dev libxml2-dev \
  postgresql nginx \
  php8.5-fpm php8.5-pgsql php8.5-gd php8.5-bcmath \
  php8.5-mbstring php8.5-xml php8.5-sockets
```

`libpq-dev` ใช้ตอนคอมไพล์ส่วน server ส่วนชุด `php8.5-*` ใช้ตอนรัน frontend

## 2. โหลดซอร์สโค้ด

```bash
git clone https://github.com/stkt99-maker/DemoZabbix7.4.15.git
cd DemoZabbix7.4.15
```

## 3. คอมไพล์และติดตั้ง

```bash
./configure --enable-server --enable-agent --with-postgresql \
            --with-net-snmp --with-libcurl --with-libxml2 --with-openssl
make -j"$(nproc)"
sudo make install
```

คอนฟิกนี้ไม่ได้เปิด: SSH checks (libssh2), IPMI, proxy และ agent2 — ถ้าต้องการใช้ ให้เพิ่ม flag เช่น `--enable-proxy` แล้วคอมไพล์ใหม่

เมื่อ `make install` เสร็จ ไบนารีจะอยู่ที่ `/usr/local/sbin` ส่วนไฟล์คอนฟิกอยู่ที่ `/usr/local/etc`

## 4. สร้างผู้ใช้ระบบและโฟลเดอร์ล็อก

```bash
sudo useradd -r -s /usr/sbin/nologin zabbix
sudo mkdir -p /var/log/zabbix
sudo chown zabbix:zabbix /var/log/zabbix
```

## 5. สร้างฐานข้อมูล PostgreSQL

```bash
sudo -u postgres psql
```

```sql
CREATE ROLE zabbix LOGIN PASSWORD 'ใส่รหัสผ่านที่นี่';
CREATE DATABASE zabbix OWNER zabbix;
\q
```

โหลด schema ตามลำดับสามไฟล์นี้ — ใส่ `export PGPASSWORD='ใส่รหัสผ่านที่นี่'` ก่อนก็จะไม่ต้องกรอกรหัสผ่านซ้ำทุกคำสั่ง:

```bash
cd database/postgresql
psql -h 127.0.0.1 -U zabbix -d zabbix -f schema.sql
psql -h 127.0.0.1 -U zabbix -d zabbix -f images.sql
psql -h 127.0.0.1 -U zabbix -d zabbix -f data.sql
```

ถ้าจะใช้ TimescaleDB ให้โหลดไฟล์เพิ่มจากโฟลเดอร์ `timescaledb/` — ข้ามได้ ไม่จำเป็นสำหรับเดโม

## 6. ตั้งค่า Zabbix Server

แก้ไฟล์ `/usr/local/etc/zabbix_server.conf` — ค่าที่ต้องมีอย่างต่ำ:

```ini
LogFile=/var/log/zabbix/zabbix_server.log
DBHost=127.0.0.1
DBName=zabbix
DBUser=zabbix
DBPassword=ใส่รหัสผ่านที่นี่
```

เครื่องที่ใช้จริงมี RAM 3.3 GB ใช้ค่า cache ชุดนี้ — ถ้าเครื่องมี RAM น้อยกว่า 2 GB ให้ลดลงครึ่งหนึ่ง:

```ini
CacheSize=128M
HistoryCacheSize=64M
TrendCacheSize=32M
ValueCacheSize=64M
```

## 7. รันผ่าน systemd

สร้าง `/etc/systemd/system/zabbix-server.service`:

```ini
[Unit]
Description=Zabbix Server
After=network.target postgresql.service

[Service]
Type=forking
PIDFile=/tmp/zabbix_server.pid
ExecStart=/usr/local/sbin/zabbix_server -c /usr/local/etc/zabbix_server.conf
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

และ `/etc/systemd/system/zabbix-agent.service`:

```ini
[Unit]
Description=Zabbix Agent
After=network.target

[Service]
Type=forking
PIDFile=/tmp/zabbix_agentd.pid
ExecStart=/usr/local/sbin/zabbix_agentd -c /usr/local/etc/zabbix_agentd.conf
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

เปิดใช้งาน:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now zabbix-server zabbix-agent
```

## 8. ติดตั้ง Frontend

ก๊อปโฟลเดอร์ `ui/` ขึ้นไป:

```bash
sudo cp -r ui /usr/share/zabbix
```

สร้าง vhost ที่ `/etc/nginx/sites-available/zabbix.conf`:

```nginx
server {
    listen 80 default_server;
    server_name _;
    root /usr/share/zabbix;
    index index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

สลับ vhost เดิมออกแล้วรีโหลด Nginx:

```bash
sudo ln -s /etc/nginx/sites-available/zabbix.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

ปรับค่า PHP ที่ `/etc/php/8.5/fpm/conf.d/90-zabbix.ini` — frontend ของ 7.4 ตรวจค่าพวกนี้ตอน setup:

```ini
memory_limit = 256M
post_max_size = 32M
upload_max_filesize = 16M
max_execution_time = 300
max_input_time = 300
max_input_vars = 10000
date.timezone = Asia/Bangkok
```

```bash
sudo systemctl restart php8.5-fpm
```

## 9. เชื่อม Frontend เข้ากับฐานข้อมูล

สร้างไฟล์ `/usr/share/zabbix/conf/zabbix.conf.php` เองได้เลย แล้วจะข้ามหน้า setup wizard ไปได้:

```php
<?php
$DB['TYPE']     = 'POSTGRESQL';
$DB['SERVER']   = '127.0.0.1';
$DB['PORT']     = '0';
$DB['DATABASE'] = 'zabbix';
$DB['USER']     = 'zabbix';
$DB['PASSWORD'] = 'ใส่รหัสผ่านที่นี่';

$ZBX_SERVER      = '127.0.0.1';
$ZBX_SERVER_PORT = '10051';
$ZBX_SERVER_NAME = 'Zabbix 7.4.15';

$IMAGE_FORMAT_DEFAULT = IMAGE_FORMAT_PNG;
```

```bash
sudo chown www-data:www-data /usr/share/zabbix/conf/zabbix.conf.php
```

## 10. เข้าใช้งาน

เปิด `http://<IP เครื่อง>/` แล้วล็อกอินด้วย `Admin` / `zabbix` (ตัว A ใหญ่) — เปลี่ยนรหัสผ่านทันทีหลังเข้าครั้งแรก

เช็คสถานะฝั่ง server:

```bash
systemctl status zabbix-server
tail -f /var/log/zabbix/zabbix_server.log
```

## เปลี่ยนโลโก้ของระบบ (Branding)

Zabbix 7.4 รองรับการเปลี่ยนโลโก้ผ่าน `CBrandHelper` + ไฟล์ `ui/local/conf/brand.conf.php` อยู่แล้ว ซอร์สใน repo นี้เพิ่มหน้าจัดการให้ด้วย — เมนู **การดูแลระบบ → โลโก้ (Branding)** ซึ่งเห็นเฉพาะ Super admin (ป้ายเมนูเปลี่ยนตามภาษาที่ใช้: `Logo (Branding)` ในโหมดอังกฤษ ผ่านระบบแปล gettext) อัปโหลดรูป (PNG/JPG/SVG ไม่เกิน 2 MB) แล้วโลโก้ใหม่จะแสดงทั้งแถบข้าง ไอคอนยุบแถบข้าง และหน้าล็อกอิน ปุ่มรีเซ็ตกลับเป็นโลโก้ Zabbix เริ่มต้นอยู่ในหน้าเดียวกัน

ไฟล์ที่เกี่ยว: `ui/brand_admin.php` (หน้าจัดการ + แก้ `CRouter.php`/`CMenuHelper.php` ลงทะเบียนเส้นทางและเมนู), `ui/local/img/` เก็บรูปที่อัปโหลด, `ui/local/conf/brand.conf.php` สร้างอัตโนมัติ — สองโฟลเดอร์หลังเป็นสถานะของเครื่องจริง จึงไม่เก็บใน repo

## หมายเหตุ

- ถ้าเครื่องมีแพ็กเกจ zabbix ของ distro ติดตั้งอยู่ก่อน การติดตั้งแบบนี้ไม่ชนกัน เพราะทั้งหมดอยู่ที่ `/usr/local`
- ซอร์สใน repo นี้ตรงกับ frontend ที่รันจริงบนเซิร์ฟเวอร์เดโมทุกไฟล์ ยกเว้น `ui/conf/zabbix.conf.php` ที่เป็นค่าเฉพาะเครื่อง (มีรหัสผ่านฐานข้อมูล) จึงไม่เก็บไว้ใน repo — สร้างเองตามขั้นที่ 9
- คอนฟิกต่าง ๆ ในคู่มือนี้อ้างอิงจากการติดตั้งจริงวันที่ 2 ต.ค. 2026 บน VM 2 cores / RAM 3.3 GB
