# Mobile / Firebase / Employee Admin — Complete Technical Reference

> **Purpose:** Full reverse-engineering doc for porting the Symfony mobile admin panel, employee management, and Firebase integration to a JavaFX desktop application.
> **Chosen approach: Option B** — JavaFX connects directly to MySQL (JDBC) and Firebase Realtime Database (Firebase Java Admin SDK). No Symfony server required.

---

## Table of Contents

1. [MySQL Database Schema](#1-mysql-database-schema)
2. [Firebase Realtime Database Collections](#2-firebase-realtime-database-collections)
3. [Entity Relationships](#3-entity-relationships)
4. [Symfony Routes Reference](#4-symfony-routes-reference)
5. [Business Logic & Rules](#5-business-logic--rules)
6. [FirebaseMobileService Methods](#6-firebasemobileservice-methods)
7. [Admin UI Behaviour](#7-admin-ui-behaviour)
8. [JavaFX Implementation Guide](#8-javafx-implementation-guide)

---

## 1. MySQL Database Schema

### Table: `utilisateur`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id_u` | INT | NO | AUTO_INCREMENT | Primary Key |
| `nom_u` | VARCHAR(50) | YES | NULL | Last name |
| `prenom_u` | VARCHAR(50) | YES | NULL | First name |
| `email_u` | VARCHAR(100) | YES | NULL | Must be unique |
| `mot_de_passe_u` | VARCHAR(100) | YES | NULL | bcrypt hashed password |
| `role_u` | VARCHAR(30) | YES | NULL | `employee` \| `client` \| `admin` \| `administrateur` |
| `image_u` | VARCHAR(255) | YES | `default.JPG` | Profile photo filename |
| `photo_face` | VARCHAR(255) | YES | `default_face.png` | Face recognition photo |
| `date_creation_u` | DATETIME | YES | CURRENT_TIMESTAMP | Account creation time |
| `fingerprintTemplate` | BLOB | YES | NULL | Biometric data (binary) |
| `fingerprintLength` | INT UNSIGNED | YES | NULL | Byte length of fingerprint blob |

**Roles that get synced to Firebase:** `employee` and `employe` only. Clients and admins are NOT synced.

**Firebase UID format:** `emp_{id_u}` — e.g. user with `id_u=5` gets Firebase UID `emp_5`.

---

### Table: `notification`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | INT | NO | AUTO_INCREMENT | Primary Key |
| `message` | VARCHAR(255) | YES | NULL | Notification text |
| `isRead` | TINYINT(1) | NO | `0` | Boolean read/unread flag |
| `createdAt` | DATETIME | NO | — | Immutable creation timestamp |
| `user_id` | INT | NO | — | FK → `utilisateur.id_u` |

---

## 2. Firebase Realtime Database Collections

**Project ID:** `elfirma-project`
**Database URI:** `https://elfirma-project-default-rtdb.firebaseio.com`
**Root path for mobile data:** `mobile/`

---

### Collection: `mobile/agriculteurs`

One document per employee. Key = Firebase UID (`emp_{id_u}`).

| Field | Type | Notes |
|---|---|---|
| `firebase_uid` | String | `emp_{mysql_id}` — matches the document key |
| `mysql_user_id` | Integer | `id_u` value from `utilisateur` table |
| `full_name` | String | `prenom_u + " " + nom_u` |
| `first_name` | String | `prenom_u` |
| `last_name` | String | `nom_u` |
| `email` | String | `email_u` |
| `role` | String | `role_u` |
| `nfc_uid` | String | SHA256 hash of the physical NFC card UID (or empty string) |
| `auth_sync` | String | `"ok"` if Firebase Auth user created, `"skipped"` if Auth API unavailable |
| `updated_at` | String | ISO 8601 timestamp of last sync |

**Path example:** `mobile/agriculteurs/emp_5`

---

### Collection: `mobile/employee_nfc_links`

One document per NFC card. Key = SHA256 hash of the raw NFC UID.

| Field | Type | Notes |
|---|---|---|
| `nfc_uid` | String | Raw NFC card UID (physical card identifier) |
| `nfc_hash` | String | SHA256 hex hash of `nfc_uid` — matches document key |
| `firebase_uid` | String | `emp_{mysql_id}` of the linked employee |
| `mysql_user_id` | Integer | `id_u` of the linked employee |
| `employee_name` | String | Full name of the employee |
| `email` | String | Email of the employee |
| `updated_at` | String | ISO 8601 timestamp |

**Path example:** `mobile/employee_nfc_links/{sha256_of_nfc_uid}`

**Purpose:** Mobile app scans NFC card → computes SHA256 → looks up this collection → identifies employee.

---

### Collection: `mobile/tasks`

One document per task. Key = `task_{random_hex}`.

| Field | Type | Notes |
|---|---|---|
| `title` | String | Task title |
| `description` | String | Task description |
| `assigned_employee_uid` | String | Firebase UID of assigned employee (`emp_{id}`) |
| `assigned_employee_mysql_id` | Integer | `id_u` of assigned employee |
| `assigned_employee_name` | String | Full name of assigned employee |
| `status` | String | `assigned` \| `in_progress` \| `done` |
| `priority` | String | `normal` \| `high` \| `urgent` |
| `due_date` | String | Date string (e.g. `"2026-05-15"`) |
| `created_by` | String | Name/identifier of admin who created the task |
| `source` | String | `"symfony_admin"` or `"javafx_admin"` |
| `created_at` | String | ISO 8601 creation timestamp |
| `updated_at` | String | ISO 8601 last update timestamp |
| `last_updated_by` | String | Optional — who last changed the status |

**Path example:** `mobile/tasks/task_a3f9c12b`

**Task ID generation:** `"task_" + random 8-char hex string`

---

### Collection: `mobile/mobile_reclamations`

One document per field report submitted from the mobile app. Created by the mobile app, read-only from admin.

| Field | Type | Notes |
|---|---|---|
| `employee_name` | String | Name of submitting employee (also stored as `reported_by_name`) |
| `title` | String | Report title (also stored as `type`) |
| `description` | String | Report body (also stored as `message`) |
| `status` | String | Default `"new"`, updated to `"reviewed"` by admin |
| `reported_at` | String | ISO 8601 timestamp (also stored as `created_at`) |

> **Note:** The mobile app may use slightly different field names (`type` vs `title`, `message` vs `description`, `reported_by_name` vs `employee_name`). Always check both when reading.

---

## 3. Entity Relationships

```
utilisateur (MySQL)
    │
    ├──< notification (N)    [user_id FK]
    │       Notifications for a user
    │
    └──< reclamation (N)     [user_id FK]
            User complaints (separate from mobile reports)

utilisateur.id_u
    │
    └── Firebase UID = "emp_{id_u}"
            │
            ├── mobile/agriculteurs/emp_{id_u}     (employee profile)
            ├── mobile/tasks/*.assigned_employee_uid  (tasks assigned to this employee)
            └── mobile/employee_nfc_links/*.firebase_uid  (NFC card links)
```

---

## 4. Symfony Routes Reference

> These routes exist in Symfony for reference. JavaFX does **not** call them — it accesses MySQL and Firebase directly.

### User Management Routes

| Route | Method | Name | Description |
|---|---|---|---|
| `/elfirma/utilisateurs` | GET | `user_page` | List all users + stats + complaints |
| `/elfirma/user/add` | POST | `elfirma_add_user` | Create new user |
| `/elfirma/user/update` | POST | `elfirma_update_user` | Update user data/photo |
| `/elfirma/user/delete` | POST | `elfirma_delete_user` | Delete user by ID |
| `/elfirma/user/{id}/profile` | GET | `elfirma_user_profile` | Public profile page |

### Mobile Admin Routes

| Route | Method | Name | Description |
|---|---|---|---|
| `/admin/mobile` | GET | `admin_mobile_home` | Redirects to employees |
| `/admin/mobile/employees` | GET/POST | `admin_mobile_employees` | Employee list + Firebase sync + NFC enrollment |
| `/admin/mobile/tasks` | GET | `admin_mobile_tasks` | List tasks from Firebase |
| `/admin/mobile/tasks/create` | POST | `admin_mobile_task_create` | Create new task in Firebase |
| `/admin/mobile/tasks/{taskId}/status` | POST | `admin_mobile_task_status` | Update task status |
| `/admin/mobile/tasks/{taskId}/delete` | POST | `admin_mobile_task_delete` | Delete task |
| `/admin/mobile/reports` | GET | `admin_mobile_reports` | List reports from Firebase |

---

## 5. Business Logic & Rules

### Employee → Firebase Sync

Only employees with `role_u IN ('employee', 'employe')` are synced.

```
For each qualifying employee:
  1. Build Firebase UID: "emp_" + id_u
  2. Try to create Firebase Auth user:
       - UID    = "emp_{id_u}"
       - email  = email_u (only if valid format)
       - name   = prenom_u + " " + nom_u
       - disabled = false
     If Auth API fails → set auth_sync = "skipped"
     If user already exists → skip creation, set auth_sync = "ok"
  3. Write profile to mobile/agriculteurs/emp_{id_u}:
       firebase_uid, mysql_user_id, full_name, first_name,
       last_name, email, role, nfc_uid (from existing profile or ""),
       auth_sync, updated_at
  4. Count: processed++, created++ (new) or updated++ (existing)
```

**Sync result summary:** `{ processed, created, updated, errors, error_messages[] }`

---

### NFC Card Enrollment

```
Input: employee (Utilisateur), raw NFC UID string

1. Compute nfc_hash = SHA256(nfc_uid).toHexString()
2. Look up existing profile at mobile/agriculteurs/emp_{id_u}
3. Update profile: set nfc_uid = nfc_hash
4. Write NFC link at mobile/employee_nfc_links/{nfc_hash}:
     nfc_uid, nfc_hash, firebase_uid, mysql_user_id,
     employee_name, email, updated_at
5. Return: { firebase_uid, mysql_user_id, created, auth_user_created,
             auth_sync_skipped, nfc_uid }
```

---

### Task Lifecycle

```
States:    assigned → in_progress → done

Creation:
  - Validate assignee has a Firebase profile
  - Generate taskId = "task_" + random 8 hex chars
  - Write to mobile/tasks/{taskId}
  - source = "symfony_admin" (use "javafx_admin" in JavaFX)

Status update:
  - Write mobile/tasks/{taskId}/status = new_status
  - Write mobile/tasks/{taskId}/updated_at = now()

Deletion:
  - DELETE mobile/tasks/{taskId}
```

---

### Report Status Update

Reports are created by the mobile app (`status = "new"`). Admin can mark them reviewed:
- Write `mobile/mobile_reclamations/{reportId}/status = "reviewed"`

---

### Access Control (replicate in JavaFX)

The Symfony admin checks:
- Session has `user_id > 0`
- `role_u` is `admin` or `administrateur`

In JavaFX: check the `role_u` column after login before showing the admin panel.

---

## 6. FirebaseMobileService Methods

**File:** `src/Service/FirebaseMobileService.php`
**Firebase DB root path:** `"mobile"`

| Method | Input | Output | Description |
|---|---|---|---|
| `syncEmployees(array $employees)` | Array of Utilisateur | `{processed, created, updated, errors, error_messages}` | Bulk sync all employees to Firebase |
| `enrollEmployeeNfc(Utilisateur, string $nfcUid)` | Employee + raw NFC UID | `{firebase_uid, mysql_user_id, created, auth_user_created, auth_sync_skipped, nfc_uid}` | Link NFC card to employee |
| `listTasks(int $limit=200)` | limit | Array of task arrays, sorted by updated_at DESC | Read all tasks from Firebase |
| `createTask(array $payload)` | task fields | `taskId` string | Write new task to Firebase |
| `updateTaskStatus(string $taskId, string $status)` | taskId, new status | void | Patch status field in Firebase |
| `deleteTask(string $taskId)` | taskId | void | Remove task document from Firebase |
| `listReports(int $limit=200)` | limit | Array of report arrays, sorted by reported_at DESC | Read all reports from Firebase |
| `getEmployeeProfiles()` | — | Array of all agriculteur documents | Read all Firebase employee profiles |
| `getEmployeeProfilesByMysqlId()` | — | Array keyed by mysql_user_id | Read profiles indexed by MySQL ID |
| `isEmployeeCandidate(Utilisateur)` | employee | bool | Check if role is employee/employe |

---

## 7. Admin UI Behaviour

### Employees Page (`/admin/mobile/employees`)

**Stats cards:**
- Total Team Members = count of `role_u IN ('employee','employe')` from MySQL
- Profiles Ready = count of Firebase docs in `mobile/agriculteurs` with `auth_sync = "ok"`

**Table columns:** Employee name | Email | Profile Status (Ready/Pending) | NFC Card (hash or "Not assigned") | Update Card (inline form)

**"Refresh Team" button:** POST `action=sync_all` → calls `syncEmployees()` → flash message with result counts

**NFC enrollment:** Inline form per row → POST `action=enroll_nfc`, `employee_id={id}`, `nfc_uid={raw_uid}` → calls `enrollEmployeeNfc()`

---

### Tasks Page (`/admin/mobile/tasks`)

**Create Assignment form fields:**
- `title` — text input (required)
- `assignee_id` — select from employees list (required)
- `priority` — select: `normal` / `high` / `urgent`
- `due_date` — date picker
- `description` — textarea (required)

**Task table columns:** Title + description subtext | Employee name | Priority badge | Status badge | Due date | Actions (status dropdown + Update button + Delete button)

**Priority badge colours:**
- `normal` → green
- `high` → orange/yellow
- `urgent` → red

**Status badge colours:**
- `assigned` → grey
- `in_progress` → blue
- `done` → green

---

### Reports Page (`/admin/mobile/reports`)

**Table columns:** Employee | Title | Description | Status | Reported At

**Status values:** `new` (yellow badge) → `reviewed` (green badge)

**Data source:** Firebase `mobile/mobile_reclamations`, sorted by `reported_at` DESC, limit 200.

---

## 8. JavaFX Implementation Guide

> **Approach:** Direct MySQL (JDBC) for user/employee/notification data. Direct Firebase Realtime Database (Firebase Admin Java SDK) for mobile collections. No Symfony server required.

---

### Project Structure

```
JavaFX App
│
├── model/
│   ├── Utilisateur.java
│   ├── Notification.java
│   ├── EmployeeFirebaseProfile.java   ← mobile/agriculteurs doc
│   ├── NfcLink.java                   ← mobile/employee_nfc_links doc
│   ├── MobileTask.java                ← mobile/tasks doc
│   └── MobileReport.java              ← mobile/mobile_reclamations doc
│
├── service/
│   ├── EmployeeDbService.java         ← JDBC queries on utilisateur + notification
│   └── FirebaseMobileService.java     ← Firebase Admin SDK calls
│
├── controller/
│   ├── EmployeesAdminController.java  ← JavaFX FXML controller
│   ├── TasksAdminController.java
│   └── ReportsAdminController.java
│
└── view/
    ├── employees_admin.fxml
    ├── tasks_admin.fxml
    └── reports_admin.fxml
```

---

### Maven Dependencies

```xml
<!-- MySQL JDBC driver -->
<dependency>
    <groupId>com.mysql</groupId>
    <artifactId>mysql-connector-j</artifactId>
    <version>8.3.0</version>
</dependency>

<!-- Firebase Admin SDK (includes Realtime Database) -->
<dependency>
    <groupId>com.google.firebase</groupId>
    <artifactId>firebase-admin</artifactId>
    <version>9.2.0</version>
</dependency>

<!-- For SHA256 hashing (NFC enrollment) — built into Java, no extra dep needed -->
```

---

### MySQL JDBC Setup

```java
// EmployeeDbService.java
private static final String URL  = "jdbc:mysql://172.20.10.5:3306/personne?useSSL=false&serverTimezone=UTC";
private static final String USER = "pi_writer";
private static final String PASS = "your_password";

public Connection getConnection() throws SQLException {
    return DriverManager.getConnection(URL, USER, PASS);
}
```

---

### All JDBC Queries

#### Load all employees (for ComboBox / TableView)
```java
public List<Utilisateur> fetchAllUsers() throws SQLException {
    String sql = "SELECT id_u, nom_u, prenom_u, email_u, role_u, image_u, date_creation_u " +
                 "FROM utilisateur ORDER BY nom_u ASC";
    try (Connection c = getConnection();
         PreparedStatement ps = c.prepareStatement(sql);
         ResultSet rs = ps.executeQuery()) {
        List<Utilisateur> list = new ArrayList<>();
        while (rs.next()) {
            Utilisateur u = new Utilisateur();
            u.setId(rs.getInt("id_u"));
            u.setNom(rs.getString("nom_u"));
            u.setPrenom(rs.getString("prenom_u"));
            u.setEmail(rs.getString("email_u"));
            u.setRole(rs.getString("role_u"));
            u.setImage(rs.getString("image_u"));
            u.setDateCreation(rs.getTimestamp("date_creation_u"));
            list.add(u);
        }
        return list;
    }
}
```

#### Fetch only employees (for Firebase sync / task assignment)
```java
public List<Utilisateur> fetchEmployees() throws SQLException {
    String sql = "SELECT id_u, nom_u, prenom_u, email_u, role_u " +
                 "FROM utilisateur WHERE role_u IN ('employee','employe') ORDER BY nom_u ASC";
    // same ResultSet mapping as above
}
```

#### Add user
```java
public int addUser(Utilisateur u, String hashedPassword) throws SQLException {
    String sql = "INSERT INTO utilisateur (nom_u, prenom_u, email_u, mot_de_passe_u, role_u, image_u, date_creation_u) " +
                 "VALUES (?, ?, ?, ?, ?, 'default.JPG', NOW())";
    try (Connection c = getConnection();
         PreparedStatement ps = c.prepareStatement(sql, Statement.RETURN_GENERATED_KEYS)) {
        ps.setString(1, u.getNom());
        ps.setString(2, u.getPrenom());
        ps.setString(3, u.getEmail());
        ps.setString(4, hashedPassword);
        ps.setString(5, u.getRole());
        ps.executeUpdate();
        try (ResultSet keys = ps.getGeneratedKeys()) {
            return keys.next() ? keys.getInt(1) : -1;
        }
    }
}
```

#### Update user
```java
public void updateUser(Utilisateur u) throws SQLException {
    String sql = "UPDATE utilisateur SET nom_u=?, prenom_u=?, email_u=?, role_u=? WHERE id_u=?";
    try (Connection c = getConnection();
         PreparedStatement ps = c.prepareStatement(sql)) {
        ps.setString(1, u.getNom());
        ps.setString(2, u.getPrenom());
        ps.setString(3, u.getEmail());
        ps.setString(4, u.getRole());
        ps.setInt(5, u.getId());
        ps.executeUpdate();
    }
}
```

#### Delete user
```java
public void deleteUser(int id) throws SQLException {
    String sql = "DELETE FROM utilisateur WHERE id_u = ?";
    try (Connection c = getConnection();
         PreparedStatement ps = c.prepareStatement(sql)) {
        ps.setInt(1, id);
        ps.executeUpdate();
    }
}
```

#### Check email uniqueness
```java
public boolean emailExists(String email, int excludeId) throws SQLException {
    String sql = "SELECT COUNT(*) FROM utilisateur WHERE email_u = ? AND id_u != ?";
    try (Connection c = getConnection();
         PreparedStatement ps = c.prepareStatement(sql)) {
        ps.setString(1, email);
        ps.setInt(2, excludeId);
        try (ResultSet rs = ps.executeQuery()) {
            return rs.next() && rs.getInt(1) > 0;
        }
    }
}
```

#### Notifications for a user
```java
public List<Notification> fetchNotifications(int userId) throws SQLException {
    String sql = "SELECT id, message, isRead, createdAt FROM notification " +
                 "WHERE user_id = ? ORDER BY createdAt DESC";
    try (Connection c = getConnection();
         PreparedStatement ps = c.prepareStatement(sql)) {
        ps.setInt(1, userId);
        try (ResultSet rs = ps.executeQuery()) {
            List<Notification> list = new ArrayList<>();
            while (rs.next()) {
                Notification n = new Notification();
                n.setId(rs.getInt("id"));
                n.setMessage(rs.getString("message"));
                n.setRead(rs.getBoolean("isRead"));
                n.setCreatedAt(rs.getTimestamp("createdAt"));
                list.add(n);
            }
            return list;
        }
    }
}
```

#### Mark notification read
```java
public void markNotificationRead(int notificationId) throws SQLException {
    String sql = "UPDATE notification SET isRead = 1 WHERE id = ?";
    try (Connection c = getConnection();
         PreparedStatement ps = c.prepareStatement(sql)) {
        ps.setInt(1, notificationId);
        ps.executeUpdate();
    }
}
```

---

### Firebase Admin SDK Setup

Place your `serviceAccountKey.json` (downloaded from Firebase Console → Project Settings → Service Accounts) in your resources folder.

```java
// FirebaseMobileService.java — initialize once at app startup
public static void initFirebase() throws IOException {
    FileInputStream serviceAccount =
        new FileInputStream("src/main/resources/serviceAccountKey.json");

    FirebaseOptions options = FirebaseOptions.builder()
        .setCredentials(GoogleCredentials.fromStream(serviceAccount))
        .setDatabaseUrl("https://elfirma-project-default-rtdb.firebaseio.com")
        .build();

    if (FirebaseApp.getApps().isEmpty()) {
        FirebaseApp.initializeApp(options);
    }
}

private DatabaseReference db() {
    return FirebaseDatabase.getInstance().getReference("mobile");
}
```

---

### Firebase Read/Write Operations

#### Read all employee profiles
```java
public List<EmployeeFirebaseProfile> getEmployeeProfiles()
        throws InterruptedException, ExecutionException {
    ApiFuture<DataSnapshot> future =
        db().child("agriculteurs").get();
    DataSnapshot snapshot = future.get();

    List<EmployeeFirebaseProfile> list = new ArrayList<>();
    for (DataSnapshot child : snapshot.getChildren()) {
        EmployeeFirebaseProfile p = child.getValue(EmployeeFirebaseProfile.class);
        list.add(p);
    }
    return list;
}
```

#### Sync one employee to Firebase
```java
public void syncEmployee(Utilisateur u) throws InterruptedException, ExecutionException {
    String firebaseUid = "emp_" + u.getId();
    Map<String, Object> profile = new HashMap<>();
    profile.put("firebase_uid",    firebaseUid);
    profile.put("mysql_user_id",   u.getId());
    profile.put("full_name",       u.getPrenom() + " " + u.getNom());
    profile.put("first_name",      u.getPrenom());
    profile.put("last_name",       u.getNom());
    profile.put("email",           u.getEmail());
    profile.put("role",            u.getRole());
    profile.put("nfc_uid",         "");
    profile.put("auth_sync",       "skipped");   // JavaFX doesn't create Auth users
    profile.put("updated_at",      Instant.now().toString());

    db().child("agriculteurs").child(firebaseUid).setValueAsync(profile).get();
}
```

#### Enroll NFC card
```java
public void enrollNfc(Utilisateur u, String rawNfcUid)
        throws InterruptedException, ExecutionException, NoSuchAlgorithmException {
    String nfcHash = sha256(rawNfcUid);
    String firebaseUid = "emp_" + u.getId();

    // Update employee profile
    db().child("agriculteurs").child(firebaseUid).child("nfc_uid").setValueAsync(nfcHash).get();

    // Write NFC link
    Map<String, Object> link = new HashMap<>();
    link.put("nfc_uid",         rawNfcUid);
    link.put("nfc_hash",        nfcHash);
    link.put("firebase_uid",    firebaseUid);
    link.put("mysql_user_id",   u.getId());
    link.put("employee_name",   u.getPrenom() + " " + u.getNom());
    link.put("email",           u.getEmail());
    link.put("updated_at",      Instant.now().toString());

    db().child("employee_nfc_links").child(nfcHash).setValueAsync(link).get();
}

private String sha256(String input) throws NoSuchAlgorithmException {
    MessageDigest digest = MessageDigest.getInstance("SHA-256");
    byte[] hash = digest.digest(input.getBytes(StandardCharsets.UTF_8));
    StringBuilder sb = new StringBuilder();
    for (byte b : hash) sb.append(String.format("%02x", b));
    return sb.toString();
}
```

#### Read all tasks
```java
public List<MobileTask> listTasks() throws InterruptedException, ExecutionException {
    DataSnapshot snapshot = db().child("tasks").get().get();
    List<MobileTask> list = new ArrayList<>();
    for (DataSnapshot child : snapshot.getChildren()) {
        MobileTask t = new MobileTask();
        t.setTaskId(child.getKey());
        t.setTitle((String) child.child("title").getValue());
        t.setDescription((String) child.child("description").getValue());
        t.setAssignedEmployeeName((String) child.child("assigned_employee_name").getValue());
        t.setAssignedEmployeeUid((String) child.child("assigned_employee_uid").getValue());
        t.setStatus((String) child.child("status").getValue());
        t.setPriority((String) child.child("priority").getValue());
        t.setDueDate((String) child.child("due_date").getValue());
        t.setCreatedAt((String) child.child("created_at").getValue());
        list.add(t);
    }
    list.sort(Comparator.comparing(MobileTask::getUpdatedAt,
              Comparator.nullsLast(Comparator.reverseOrder())));
    return list;
}
```

#### Create task
```java
public String createTask(int assigneeId, String assigneeName, String assigneeUid,
                          String title, String description, String priority,
                          String dueDate, String createdBy)
        throws InterruptedException, ExecutionException {
    String taskId = "task_" + Long.toHexString(new Random().nextLong() & 0xFFFFFFFFL);
    String now = Instant.now().toString();

    Map<String, Object> task = new HashMap<>();
    task.put("title",                       title);
    task.put("description",                 description);
    task.put("assigned_employee_uid",       assigneeUid);
    task.put("assigned_employee_mysql_id",  assigneeId);
    task.put("assigned_employee_name",      assigneeName);
    task.put("status",                      "assigned");
    task.put("priority",                    priority);
    task.put("due_date",                    dueDate);
    task.put("created_by",                  createdBy);
    task.put("source",                      "javafx_admin");
    task.put("created_at",                  now);
    task.put("updated_at",                  now);

    db().child("tasks").child(taskId).setValueAsync(task).get();
    return taskId;
}
```

#### Update task status
```java
public void updateTaskStatus(String taskId, String newStatus)
        throws InterruptedException, ExecutionException {
    Map<String, Object> updates = new HashMap<>();
    updates.put("status",     newStatus);
    updates.put("updated_at", Instant.now().toString());
    db().child("tasks").child(taskId).updateChildrenAsync(updates).get();
}
```

#### Delete task
```java
public void deleteTask(String taskId) throws InterruptedException, ExecutionException {
    db().child("tasks").child(taskId).removeValueAsync().get();
}
```

#### Read all reports
```java
public List<MobileReport> listReports() throws InterruptedException, ExecutionException {
    DataSnapshot snapshot = db().child("mobile_reclamations").get().get();
    List<MobileReport> list = new ArrayList<>();
    for (DataSnapshot child : snapshot.getChildren()) {
        MobileReport r = new MobileReport();
        r.setReportId(child.getKey());
        // handle both possible field names
        r.setEmployeeName(getStr(child, "employee_name", "reported_by_name"));
        r.setTitle(getStr(child, "title", "type"));
        r.setDescription(getStr(child, "description", "message"));
        r.setStatus((String) child.child("status").getValue());
        r.setReportedAt(getStr(child, "reported_at", "created_at"));
        list.add(r);
    }
    list.sort(Comparator.comparing(MobileReport::getReportedAt,
              Comparator.nullsLast(Comparator.reverseOrder())));
    return list;
}

private String getStr(DataSnapshot snap, String key1, String key2) {
    Object v = snap.child(key1).getValue();
    if (v != null) return (String) v;
    v = snap.child(key2).getValue();
    return v != null ? (String) v : "";
}
```

---

### Java Model Classes

```java
// Utilisateur.java
public class Utilisateur {
    private int id;           // id_u
    private String nom;       // nom_u
    private String prenom;    // prenom_u
    private String email;     // email_u
    private String role;      // role_u
    private String image;     // image_u
    private Timestamp dateCreation; // date_creation_u

    public String getFirebaseUid() { return "emp_" + id; }
    public String getFullName()    { return prenom + " " + nom; }
    public boolean isEmployee()    { return "employee".equals(role) || "employe".equals(role); }
    // getters / setters
}

// Notification.java
public class Notification {
    private int id;
    private String message;
    private boolean read;       // isRead
    private Timestamp createdAt;
    // getters / setters
}

// EmployeeFirebaseProfile.java
public class EmployeeFirebaseProfile {
    private String firebase_uid;
    private long mysql_user_id;
    private String full_name;
    private String first_name;
    private String last_name;
    private String email;
    private String role;
    private String nfc_uid;
    private String auth_sync;
    private String updated_at;
    // getters / setters (field names MUST match Firebase keys exactly)
}

// MobileTask.java
public class MobileTask {
    private String taskId;          // document key
    private String title;
    private String description;
    private String assigned_employee_uid;
    private String assigned_employee_name;
    private int    assigned_employee_mysql_id;
    private String status;          // assigned | in_progress | done
    private String priority;        // normal | high | urgent
    private String due_date;
    private String created_by;
    private String source;
    private String created_at;
    private String updated_at;
    // getters / setters
}

// MobileReport.java
public class MobileReport {
    private String reportId;        // document key
    private String employeeName;
    private String title;
    private String description;
    private String status;          // new | reviewed
    private String reportedAt;
    // getters / setters
}
```

---

### JavaFX UI Components to Recreate

#### Employees Tab

| Symfony UI Element | JavaFX Equivalent |
|---|---|
| Total Team Members stat | `Label` populated from `fetchEmployees().size()` |
| Profiles Ready stat | `Label` from Firebase profiles with `auth_sync="ok"` count |
| "Refresh Team" button | `Button` → call `syncEmployee()` for each employee |
| Employee TableView | `TableView<Utilisateur>` with Firebase profile overlay |
| Profile Status column | `TableColumn` checking if Firebase profile exists |
| NFC Card column | `TableColumn` showing profile's `nfc_uid` or "Not assigned" |
| NFC enrollment field | `TextField` + `Button` per row → `enrollNfc()` |

#### Tasks Tab

| Symfony UI Element | JavaFX Equivalent |
|---|---|
| Title field | `TextField` |
| Assignee dropdown | `ComboBox<Utilisateur>` populated from `fetchEmployees()` |
| Priority dropdown | `ComboBox<String>` with normal/high/urgent |
| Due date picker | `DatePicker` |
| Description field | `TextArea` |
| Create button | `Button` → `createTask()` |
| Tasks TableView | `TableView<MobileTask>` |
| Priority badge | `TableColumn` with coloured `Label` cell |
| Status badge | `TableColumn` with coloured `Label` cell |
| Status update dropdown | `ComboBox<String>` per row → `updateTaskStatus()` |
| Delete button | `Button` per row → `deleteTask()` |

#### Reports Tab

| Symfony UI Element | JavaFX Equivalent |
|---|---|
| Reports TableView | `TableView<MobileReport>` |
| Employee column | `TableColumn<MobileReport, String>` |
| Title column | `TableColumn<MobileReport, String>` |
| Description column | `TableColumn<MobileReport, String>` |
| Status badge | `TableColumn` with "new" (yellow) / "reviewed" (green) cell |
| Reported At column | `TableColumn<MobileReport, String>` |

#### User Management Tab

| Symfony UI Element | JavaFX Equivalent |
|---|---|
| Stats: Total, Employees, Clients, Admins | 4× `Label` from `countByRole()` |
| Users TableView | `TableView<Utilisateur>` |
| Add user form | `Dialog` or side panel with fields |
| Edit user | Pre-fill same dialog from selected row |
| Delete user | `Alert.CONFIRMATION` → `deleteUser(id)` |
| Search filter | `TextField` with `textProperty().addListener()` |
| Role filter | `ComboBox<String>` ALL/employee/client/admin |

---

### Password Hashing in Java

The Symfony app uses bcrypt. Use `jBCrypt` to stay compatible:

```xml
<dependency>
    <groupId>de.svenkubiak</groupId>
    <artifactId>jBCrypt</artifactId>
    <version>0.4.3</version>
</dependency>
```

```java
// Hash a new password
String hashed = BCrypt.hashpw(plainPassword, BCrypt.gensalt());

// Verify at login
boolean valid = BCrypt.checkpw(plainPassword, storedHash);
```

---

### Summary Checklist: What JavaFX Must Implement

**User/Employee Management (MySQL)**
- [ ] Load all users into TableView with role filter and search
- [ ] Show stats: total, employees, clients, admins count
- [ ] Add user: validate name ≤10 chars, email unique, password 3–7 chars, role ∈ {employee,client}
- [ ] Update user: same validations, skip password if field empty
- [ ] Delete user with confirmation dialog
- [ ] Hash passwords with bcrypt (jBCrypt)

**Firebase Employee Sync**
- [ ] Initialize Firebase Admin SDK with `serviceAccountKey.json`
- [ ] Filter employees: only `role_u IN ('employee','employe')`
- [ ] Sync each employee to `mobile/agriculteurs/emp_{id_u}`
- [ ] Show sync summary: processed / created / updated / errors
- [ ] Enroll NFC: SHA256-hash raw UID, write to profile AND `employee_nfc_links`
- [ ] Show Firebase profile status per employee (exists = Ready, missing = Pending)

**Task Management (Firebase)**
- [ ] Load tasks from `mobile/tasks` into TableView, sorted by updated_at DESC
- [ ] Create task: all required fields, `source = "javafx_admin"`
- [ ] Update task status via status dropdown
- [ ] Delete task with confirmation
- [ ] Show priority and status with colour coding

**Reports (Firebase, read-only)**
- [ ] Load reports from `mobile/mobile_reclamations`, sorted by reported_at DESC
- [ ] Handle dual field names (`title` vs `type`, `description` vs `message`, etc.)
- [ ] Mark report as reviewed (write `status = "reviewed"`)

**Notifications (MySQL)**
- [ ] Load notifications for logged-in user
- [ ] Mark individual notifications as read
- [ ] Show unread count badge in nav
