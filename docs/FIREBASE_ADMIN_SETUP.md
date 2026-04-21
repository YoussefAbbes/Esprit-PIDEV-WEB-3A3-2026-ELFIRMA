Firebase Admin Setup For Mobile Module

Important
- The FlutterFire values (apiKey/appId/projectId) are client configuration.
- Symfony Firebase Admin requires a Service Account JSON key.

1) Create Service Account Key
- Firebase Console -> Project Settings -> Service accounts -> Generate new private key.
- Save as:
  config/firebase_credentials.json

2) Configure Local Environment
- In .env.local, set:
  FIREBASE_CREDENTIALS_PATH=C:/Users/youss/OneDrive/Bureau/My Projects/symfony-agriculturefinale-20260410-0258/config/firebase_credentials.json
- Set the Realtime Database URL exactly as shown in Firebase Console -> Realtime Database:
  FIREBASE_DATABASE_URI=https://<your-database-host>.firebaseio.com
  or
  FIREBASE_DATABASE_URI=https://<your-database-host>.<region>.firebasedatabase.app

Notes
- This value is required for this project.
- If Realtime Database is not created yet, create it first in Firebase Console -> Realtime Database.
- Do not guess the host. Copy/paste it from Firebase Console.

3) Run Employee Sync
- One shot:
  php bin/console app:firebase:sync-employees

- Continuous sync loop:
  php bin/console app:firebase:sync-employees --loop --interval=60

4) Open Admin Pages
- /admin/mobile/employees
- /admin/mobile/tasks
- /admin/mobile/reports

Data Location Used By Backend
- Firebase Authentication users: created/updated for employees
- Firebase Realtime Database path: mobile/
  - mobile/agriculteurs
  - mobile/employee_nfc_links
  - mobile/tasks
  - mobile/mobile_reclamations
