# Guide des changements 2FA Admin

Date: 2026-04-21

## Objectif
Corriger le flux du bouton Admin Panel:
- Si code 2FA correct: redirection vers utilisateurs.html.twig
- Si code 2FA incorrect: déconnexion + retour login + alerte SweetAlert

## Résumé du comportement final
1. Admin clique sur Admin Panel.
2. Si session 2FA admin déjà valide, accès direct à la page utilisateurs.
3. Sinon, affichage de la page QR + saisie code 2FA.
4. Si code valide, session 2FA marquée valide et redirection vers user_page.
5. Si code invalide, session invalidée, redirection vers app_login avec twofa_error=invalid_code.
6. La page login lit twofa_error et affiche une SweetAlert d'erreur.

## Fichiers modifiés

### 1) src/Controller/AdminTwoFactorController.php

#### a) Redirection succès vers utilisateurs
- Changement de redirection vers la route user_page (au lieu d'une redirection qui retombait sur le flux login).

Points clés modifiés:
- entry(): redirection si 2FA valide
- challenge() POST: redirection après vérification OK

Code clé:
```php
return $this->redirectToRoute('user_page');
```

#### b) Fallback de vérification avec secret en session
Problème traité: dans certains cas, la relecture du secret persistant peut échouer au moment du POST.

Solution ajoutée:
- Pendant l'affichage du challenge, le secret est stocké en session:
```php
$secret = $twoFactorService->getOrCreateSecretForUser($userId);
$session->set('admin_2fa_pending_secret', $secret);
```

- Au POST:
  - Vérification normale via userId
  - Si échec, fallback via secret session
```php
$verified = $twoFactorService->verifyCode($userId, $code);

if (!$verified) {
    $pendingSecret = (string) $session->get('admin_2fa_pending_secret', '');
    if ($pendingSecret !== '') {
        $verified = $twoFactorService->verifyCodeWithSecret($pendingSecret, $code);
    }
}
```

- Après succès: suppression du secret temporaire
```php
$session->remove('admin_2fa_pending_secret');
```

#### c) Tolérance sur le booléen de session 2FA
Pour éviter les faux négatifs selon sérialisation:
```php
$admin2faVerified = $session->get('admin_2fa_verified');
if (!in_array($admin2faVerified, [true, 1, '1'], true)) {
    return false;
}
```

#### d) Déconnexion sécurisée en cas de code invalide
Conserve le comportement demandé:
```php
return $this->forceLogout($request, 'invalid_code');
```

Et propagation query param:
```php
$params['twofa_error'] = $twoFaError;
```

---

### 2) src/Service/AdminTwoFactorService.php

#### a) Refactor de verifyCode()
Avant: logique complète de validation directement dans verifyCode().
Maintenant: verifyCode() délègue à une méthode commune réutilisable.

```php
return $this->verifyCodeWithSecret($secret, $code);
```

#### b) Nouvelle méthode verifyCodeWithSecret()
Permet de valider un code TOTP avec un secret fourni (utilisée par fallback session).

```php
public function verifyCodeWithSecret(string $secret, string $code): bool
{
    if ($secret === '') {
        return false;
    }

    $cleanCode = preg_replace('/\s+/', '', trim($code));
    if ($cleanCode === '' || !preg_match('/^\d{6}$/', $cleanCode)) {
        return false;
    }

    $totp = TOTP::create($secret);
    return $totp->verify($cleanCode, null, 1);
}
```

#### c) Nouvelle méthode getOrCreateSecretForUser()
Expose proprement la récupération/création du secret depuis le contrôleur.

```php
public function getOrCreateSecretForUser(int $userId): string
{
    return $this->getOrCreateSecret($userId);
}
```

---

### 3) src/Controller/ElfirmaController.php

#### Garde d'accès module utilisateurs
Ajustement du contrôle:
- Non-admin: logout + login (inchangé sur le principe)
- Admin sans 2FA valide: redirection vers challenge 2FA (au lieu logout direct)

Code clé:
```php
if ($session->get('user_role') !== 'admin') {
    $session->invalidate();
    return $this->redirectToRoute('app_login');
}

if (!AdminTwoFactorController::hasValidAdminTwoFactor($request)) {
    return $this->redirectToRoute('app_admin_panel_2fa');
}
```

---

### 4) src/Controller/UserController.php

#### Garde d'accès page utilisateurs
Même correction que dans ElfirmaController:
- Non-admin: logout + login
- Admin sans 2FA valide: retour vers challenge 2FA

Code clé:
```php
if ($session->get('user_role') !== 'admin') {
    $session->invalidate();
    return $this->redirectToRoute('app_login');
}

if (!AdminTwoFactorController::hasValidAdminTwoFactor($request)) {
    return $this->redirectToRoute('app_admin_panel_2fa');
}
```

---

### 5) templates/auth/login.html.twig

#### a) Ajout d'une source de donnée pour erreur 2FA
```twig
<div id="twofa-error-data" data-twofa-error="{{ app.request.query.get('twofa_error', '') }}"></div>
```

#### b) SweetAlert en cas d'erreur invalid_code
```javascript
const twoFaErrorData = document.getElementById('twofa-error-data');
const twoFaError = twoFaErrorData ? twoFaErrorData.getAttribute('data-twofa-error') : '';
if (twoFaError === 'invalid_code') {
  Swal.fire({
    icon: 'error',
    title: 'Incorrect verification code',
    text: 'Your admin 2FA code is invalid. Please sign in again.',
    confirmButtonColor: '#d32f2f'
  });
}
```

Note:
- Il existe aussi un ancien bloc twofa_error plus bas dans ce même fichier.
- Les deux peuvent coexister; un nettoyage peut être fait pour garder une seule alerte.

## Validation effectuée
Contrôle d'erreurs éditeur sur fichiers modifiés:
- src/Service/AdminTwoFactorService.php: OK
- src/Controller/AdminTwoFactorController.php: OK
- src/Controller/ElfirmaController.php: OK
- src/Controller/UserController.php: OK
- templates/auth/login.html.twig: OK

## Check-list de test manuel
1. Se connecter avec un compte admin.
2. Cliquer sur Admin Panel.
3. Entrer un code 2FA correct: vérifier redirection vers page utilisateurs.
4. Refaire et entrer un code incorrect: vérifier déconnexion + retour login + SweetAlert.
5. Vérifier qu'un non-admin n'accède pas à la page utilisateurs.
