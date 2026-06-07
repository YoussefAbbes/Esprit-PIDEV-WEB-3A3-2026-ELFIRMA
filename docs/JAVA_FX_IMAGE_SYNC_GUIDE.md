# JavaFX <-> Symfony Product Image Guide

Goal: make product images load in both Symfony and JavaFX.

## Single Source of Truth
- Store only the image filename in the database (e.g., "apple-123.jpg").
- Never store absolute paths like "C:\Users\...\image.jpg".

## Symfony Side (Web)
- Symfony serves images from: public/uploads/produits/
- Twig builds URLs like: /uploads/produits/<filename>
- The product entity uses the filename to render the URL.

## JavaFX Side (Desktop)
### When adding or editing a product image
Option A (recommended): Upload to Symfony
1) Send the selected image file to a Symfony endpoint (multipart/form-data).
2) Symfony saves it in public/uploads/produits/.
3) Symfony stores only the filename in DB.

Option B: Copy directly into the Symfony public folder
1) Copy the selected image file into public/uploads/produits/.
2) Save only the filename in DB.

### When displaying an image
- Build URL from a base URL + filename:
  - Base URL: http://localhost:8000/uploads/produits/
  - Full URL: base + filename

Example JavaFX usage:
- filename = produit.getImage(); // "apple-123.jpg"
- imageUrl = "http://localhost:8000/uploads/produits/" + filename
- imageView.setImage(new Image(imageUrl, true));

## Important Notes
- If the file is not inside public/uploads/produits, Symfony cannot serve it.
- Both apps must agree on the filename stored in DB.
- If a record already contains a full path, strip it to the filename.

## Quick Checklist
- [ ] DB stores filename only.
- [ ] File exists in public/uploads/produits/.
- [ ] JavaFX builds URL from base + filename.
- [ ] Symfony templates use /uploads/produits/<filename>.
