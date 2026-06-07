# Animal Photo System — Cross-Platform Guide
### For the JavaFX agent sharing the same MySQL database as the Symfony project

---

## 1. How the Symfony side works (the source of truth)

### Database column

Table: **`animal`**

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `photo_name` | `varchar(255)` | YES | Filename only — e.g. `vache-png-69eb1e3a0bdca445097015.jpg` |
| `photo_updated_at` | `datetime` | YES | Timestamp of last photo update (managed by VichUploader) |

> **Important:** `photo_name` stores **only the filename**, never a full path or URL.

---

## 2. Where the image files live on disk

All uploaded animal photos are stored under the Symfony project's public directory:

```
{SYMFONY_PROJECT_ROOT}/public/uploads/animals/{photo_name}
```

**Concrete example:**
```
C:\Users\youss\Music\yassine\symfony-agriculturefinale-20260410-0258\public\uploads\animals\vache-png-69eb1e3a0bdca445097015.jpg
```

Actual files present in that folder (sample):
```
coq-png-69eb58819004c324925371.avif
mouton-png-69eb1d5cd056d673426696.avif
poule-png-69eb1d7207eb3105434445.webp
vache-png-69eb1e3a0bdca445097015.jpg
taureau-png-69eb1f24b5199553278495.avif
cochon-png-69eb1f4574758084143063.jpg
```

Filename pattern: `{original-name-slug}-{unique-hex-hash}.{extension}`

Supported image extensions in the wild: `.jpg`, `.jpeg`, `.png`, `.webp`, `.avif`, `.gif`

---

## 3. How the Symfony web app builds the URL

```twig
{# Twig template #}
<img src="{{ asset('/uploads/animals/' ~ item.photo_name) }}">
```

Which resolves to:
```
http://localhost:8000/uploads/animals/vache-png-69eb1e3a0bdca445097015.jpg
```

---

## 4. What the JavaFX agent needs to do

### 4.1 Define the base path as a constant

```java
// Point this to wherever the Symfony project lives on the shared machine
public static final String ANIMAL_PHOTOS_DIR =
    "C:/Users/youss/Music/yassine/symfony-agriculturefinale-20260410-0258/public/uploads/animals/";
```

> If the JavaFX app and Symfony project run on different machines, either:
> - Mount the Symfony `public/uploads/animals` folder as a network share, or
> - Serve images over HTTP (`http://localhost:8000/uploads/animals/{photo_name}`) and load via URL.

---

### 4.2 Load an image from `photo_name`

```java
import javafx.scene.image.Image;
import java.io.File;

public static Image loadAnimalPhoto(String photoName) {
    if (photoName == null || photoName.isBlank()) {
        // Return a placeholder image
        return new Image(MyController.class.getResourceAsStream("/images/no-photo.png"));
    }

    File file = new File(ANIMAL_PHOTOS_DIR + photoName);
    if (!file.exists()) {
        return new Image(MyController.class.getResourceAsStream("/images/no-photo.png"));
    }

    return new Image(file.toURI().toString());
}
```

---

### 4.3 Display in a TableView — custom ImageView cell

**Animal model class:**
```java
public class Animal {
    private int    idAnimal;
    private int    idElevage;
    private String typeAnimal;
    private String sexe;
    private int    age;
    private String etatSante;
    private String statut;
    private String photoName;   // ← maps directly to DB column photo_name

    // getters / setters ...
}
```

**TableColumn with ImageView:**
```java
TableColumn<Animal, String> photoCol = new TableColumn<>("Photo");
photoCol.setCellValueFactory(new PropertyValueFactory<>("photoName"));
photoCol.setCellFactory(col -> new TableCell<>() {
    private final ImageView imageView = new ImageView();

    {
        imageView.setFitWidth(60);
        imageView.setFitHeight(60);
        imageView.setPreserveRatio(true);
        // Clip to rounded rectangle (optional, for aesthetics)
        javafx.scene.shape.Rectangle clip = new javafx.scene.shape.Rectangle(60, 60);
        clip.setArcWidth(10);
        clip.setArcHeight(10);
        imageView.setClip(clip);
    }

    @Override
    protected void updateItem(String photoName, boolean empty) {
        super.updateItem(photoName, empty);
        if (empty || photoName == null) {
            setGraphic(null);
        } else {
            imageView.setImage(loadAnimalPhoto(photoName));
            setGraphic(imageView);
        }
    }
});
tableView.getColumns().add(photoCol);
```

---

### 4.4 SQL query to fetch animals including photo

```sql
SELECT id_animal,
       id_elevage,
       type_animal,
       sexe,
       age,
       etat_sante,
       statut,
       photo_name          -- ← always include this column
FROM animal
ORDER BY id_animal DESC;
```

**JDBC mapping:**
```java
while (rs.next()) {
    Animal a = new Animal();
    a.setIdAnimal(rs.getInt("id_animal"));
    a.setIdElevage(rs.getInt("id_elevage"));
    a.setTypeAnimal(rs.getString("type_animal"));
    a.setSexe(rs.getString("sexe"));
    a.setAge(rs.getInt("age"));
    a.setEtatSante(rs.getString("etat_sante"));
    a.setStatut(rs.getString("statut"));
    a.setPhotoName(rs.getString("photo_name")); // may be null — that is fine
    list.add(a);
}
```

---

### 4.5 Adding a photo from JavaFX (optional — if you want to implement it)

If you want the JavaFX CRUD to also support photo uploads, follow the exact same convention Symfony uses so both apps stay compatible.

#### Step 1 — Let the user pick a file
```java
FileChooser chooser = new FileChooser();
chooser.getExtensionFilters().add(
    new FileChooser.ExtensionFilter("Images", "*.jpg","*.jpeg","*.png","*.webp","*.gif")
);
File chosen = chooser.showOpenDialog(stage);
```

#### Step 2 — Generate a filename matching Symfony's pattern
```java
import java.util.UUID;
import java.nio.file.Files;
import java.nio.file.StandardCopyOption;

private static String generatePhotoName(File sourceFile) {
    String originalName = sourceFile.getName()
        .replaceAll("[^a-zA-Z0-9.]", "-")   // slug the original name
        .toLowerCase();
    String uniquePart = UUID.randomUUID().toString().replace("-", "").substring(0, 22);
    String ext = originalName.contains(".")
        ? originalName.substring(originalName.lastIndexOf('.'))
        : ".jpg";
    String baseName = originalName.contains(".")
        ? originalName.substring(0, originalName.lastIndexOf('.'))
        : originalName;
    return baseName + "-" + uniquePart + ext;
    // Result example: vache-png-4f3a9b2e1c7d08fa342b91.jpg
}
```

#### Step 3 — Copy the file to the uploads folder
```java
String generatedName = generatePhotoName(chosen);
Path destination = Path.of(ANIMAL_PHOTOS_DIR + generatedName);
Files.copy(chosen.toPath(), destination, StandardCopyOption.REPLACE_EXISTING);
```

#### Step 4 — Save the filename to the database
```java
String sql = "UPDATE animal SET photo_name = ?, photo_updated_at = NOW() WHERE id_animal = ?";
try (PreparedStatement ps = conn.prepareStatement(sql)) {
    ps.setString(1, generatedName);
    ps.setInt(2, animalId);
    ps.executeUpdate();
}
```

> **Never store a full path in the database.** Only the filename (e.g. `vache-png-abc123.jpg`).  
> Both the Symfony web app and JavaFX will construct the full path themselves using their own base path constant.

---

## 5. Handling the case where photo_name is NULL

The column is nullable. Always guard against it:

```java
// In your Animal model getter
public Image getPhoto() {
    return loadAnimalPhoto(this.photoName); // loadAnimalPhoto handles null safely
}
```

In the template / cell renderer: show a grey silhouette or an "No Photo" placeholder image when `photoName` is `null` or blank.

---

## 6. Quick reference summary

| What | Value |
|------|-------|
| **DB table** | `animal` |
| **DB column** | `photo_name` (varchar 255, nullable) |
| **DB timestamp** | `photo_updated_at` (datetime, nullable) |
| **Files on disk** | `{symfony_root}/public/uploads/animals/` |
| **File name only in DB** | ✅ Yes — never a full path |
| **Web URL** | `http://localhost:8000/uploads/animals/{photo_name}` |
| **Allowed formats** | jpg, jpeg, png, webp, avif, gif |
| **Max size (Symfony)** | 5 MB |
| **Namer strategy** | `{slug}-{22-char-hex}.{ext}` |
| **JavaFX load method** | `new Image(new File(BASE_DIR + photoName).toURI().toString())` |

---

## 7. Checklist before running JavaFX

- [ ] `ANIMAL_PHOTOS_DIR` constant points to the correct absolute path
- [ ] A fallback placeholder image exists in your JavaFX resources (`/images/no-photo.png` or similar)
- [ ] Your SQL query includes `photo_name` in the SELECT
- [ ] Your `Animal` POJO has a `photoName` field (String, nullable)
- [ ] Your TableCell uses `loadAnimalPhoto()` which handles null safely
- [ ] You are **not** storing a full path in the database when saving new photos
