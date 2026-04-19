# Fiche rapide - ProductController et CategoryController

## ProductController

- `adminIndex()` : Affiche la page d'administration des produits avec recherche, filtres, tri, statistiques et donnees graphiques.
- `exportProductsPdf()` : Exporte la liste des produits filtres vers une vue de rapport imprimable/PDF.
- `createProduit()` : Cree un produit a partir du formulaire, gere l'upload image, valide l'entite puis persiste en base.
- `editProduit()` : Modifie un produit existant, gere le remplacement d'image, valide l'entite puis enregistre les changements.
- `deleteProduit()` : Supprime un produit par son identifiant avec message de succes/erreur.
- `listProduitsApi()` : Retourne la liste des produits au format JSON pour consommation frontend/API.
- `details()` : Affiche la page detail d'un produit cote site web.
- `apiDetails()` : Retourne le detail d'un produit au format JSON, ou 404 s'il est introuvable.
- `createOrder()` : Cree une commande depuis l'API, verifie disponibilite/stock, puis met a jour le stock produit.
- `catalog()` : Affiche le catalogue public des produits disponibles, tries par nom.
- `apiCatalog()` : Retourne le catalogue des produits disponibles au format JSON avec filtre categorie optionnel.
- `parseDateValue()` : Convertit une date texte du formulaire (`Y-m-d`) en objet date PHP ou `null` si invalide/vide.
- `appendEntityValidationErrors()` : Transforme les violations de validation Symfony en tableau d'erreurs lisible par le formulaire.
- `buildUploadFilename()` : Genere un nom de fichier image propre et unique pour eviter collisions et noms invalides.
- `resolveUploadExtension()` : Determine et normalise l'extension du fichier upload pour securiser le nom final.
- `filterAndSortProducts()` : Applique recherche, filtres (categorie/statut) et tri sur la liste des produits.
- `normalizeFilterText()` : Normalise une chaine (casse/accents/caracteres) pour fiabiliser recherche et comparaison.
- `normalizeStatus()` : Uniformise les variantes de statut (fr/en) vers une forme comparable.
- `buildProductStats()` : Calcule les indicateurs metier produits (stock, valeur, disponibilite, top categorie).
- `buildProductChartData()` : Construit les donnees structurees pour les graphiques produits.
- `buildSimplePdf()` : Construit un PDF simple (objet PDF brut) a partir d'un tableau de lignes texte.
- `escapePdfText()` : Echappe le texte pour le format PDF afin d'eviter la corruption du contenu.

## CategoryController

- `index()` : Affiche la page categories avec recherche, tri et statistiques globales.
- `exportPdf()` : Exporte la liste des categories filtrees vers un PDF simple.
- `create()` : Cree une categorie, valide l'entite Categorie puis persiste en base.
- `edit()` : Met a jour une categorie existante apres validation de l'entite.
- `delete()` : Supprime une categorie si elle n'est pas liee a des produits.
- `filterAndSortCategories()` : Applique recherche et tri sur la liste des categories.
- `appendCategoryValidationErrors()` : Convertit les violations de validation en tableau d'erreurs formulaire.
- `normalizeFilterText()` : Normalise le texte de recherche pour comparaison fiable.
- `buildSimplePdf()` : Genere un PDF minimal base sur du texte ligne par ligne.
