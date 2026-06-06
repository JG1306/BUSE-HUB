# BUSE-HUB

Campus services marketplace for Bindura University — students (**client**) and businesses (**provider**).

## Structure

```
BUSE-HUB/
├── backend/
│   ├── config/db.php      # PDO connection + JSON helpers
│   ├── api/register.php   # POST sign-up (role: client | provider)
│   ├── api/login.php      # POST sign-in (role must match account)
│   └── sql/schema.sql     # MySQL schema
└── frontend/              # Next.js + Tailwind CSS
```

## Setup (XAMPP + Next.js)

### 1. Database

1. Start **Apache** and **MySQL** in XAMPP.
2. Open phpMyAdmin and import `backend/sql/schema.sql`.
3. Adjust credentials in `backend/config/db.php` if needed (default: `root` / empty password).

### 2. PHP API

Place this project where Apache can serve it, e.g.:

- Symlink or copy to `htdocs/BUSE-HUB`, or
- Point your vhost document root at `/home/joel/Desktop/BUSE-HUB`

**Deploy PHP to XAMPP** (required — Apache serves `/opt/lampp/htdocs/buse-hub/`, not your Desktop folder):

```bash
./scripts/sync-backend-to-xampp.sh
```

Or symlink once (edits apply automatically):

```bash
sudo rm -rf /opt/lampp/htdocs/buse-hub/backend
sudo ln -s /home/joel/Desktop/BUSE-HUB/backend /opt/lampp/htdocs/buse-hub/backend
```

Import `businesses` table if needed: run `backend/sql/schema.sql` in phpMyAdmin.

Test: `http://localhost/buse-hub/backend/api/login.php` (JSON "Method not allowed" on GET).

### 3. Frontend

```bash
cd frontend
npm install
npm run dev
```

Open [http://localhost:3000](http://localhost:3000).

The frontend calls PHP directly using your browser hostname (`localhost` or `192.168.x.x`), e.g. `http://localhost/buse-hub/backend/api/...`. Set `NEXT_PUBLIC_APACHE_BASE_PATH` in `frontend/.env.local` if your htdocs folder differs.

Run `npm run dev` (binds `0.0.0.0`) for LAN testing at `http://192.168.x.x:3000`.

## API

### POST `/backend/api/register.php`

```json
{
  "email": "student@example.com",
  "password": "securepass",
  "full_name": "Jane Student",
  "role": "client"
}
```

`role`: `"client"` (Student Client) or `"provider"` (Service/Business Provider).

### POST `/backend/api/login.php`

```json
{
  "email": "student@example.com",
  "password": "securepass",
  "role": "client"
}
```

## Tech stack

- **Frontend:** Next.js (App Router), React, Tailwind CSS
- **Backend:** PHP, MySQL (XAMPP), `password_hash` / `password_verify`
