# Deployment on Render

This project is configured to deploy on Render with Docker.

## Files already configured

- `Dockerfile`
  - installs PHP extensions required by Symfony
  - sets default `APP_ENV=prod`, `APP_DEBUG=0`, `PORT=10000`
  - builds production cache
  - starts PHP built-in server on `0.0.0.0:$PORT`
- `render.yaml`
  - configures the web service
  - includes environment variables for Symfony

## What you must update before deployment

In `render.yaml`, replace the placeholder values with real values:

- `APP_SECRET`
  - use the secret from your `.env` file: `bd55b7bdf932719b5c15883b0edf1bab`
- `DATABASE_URL`
  - do NOT use `127.0.0.1` on Render unless your database is running in the same container
  - use a real host name or Render database URL

Example for PostgreSQL on Render:

```yaml
- key: DATABASE_URL
  value: 'postgresql://root:monMotDePasse@db.example.com:5432/personne'
```

If you want to use a MySQL database instead, use:

```yaml
- key: DATABASE_URL
  value: 'mysql://root:monMotDePasse@db.example.com:3306/personne'
```

## Deploy steps

1. Save `render.yaml`
2. Commit and push your changes:
   ```bash
   git add render.yaml Dockerfile .dockerignore
   git commit -m "Configure Render deployment"
   git push
   ```
3. Render will automatically build and deploy your app.

## Notes

- If the app still shows a blank page, check Render logs for startup errors.
- If you use Render-managed MySQL, use the host and credentials provided by Render.
- `APP_ENV` must stay `prod` and `APP_DEBUG` must be `0` for production.
