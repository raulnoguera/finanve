# Despliegue en Hostinger (hosting compartido)

Requisitos: plan con **PHP 8.3 o 8.4** (hPanel → Avanzado → Configuración de PHP) y una base de datos **MySQL**.

1. **Crear la base de datos**: hPanel → Bases de datos → MySQL. Anota nombre, usuario, contraseña.
2. **Generar el paquete** en tu computador: `./deploy/build-zip.sh` (crea `finanzas-deploy.zip`).
3. **Subir**: Administrador de archivos → `public_html` → sube el zip y extráelo ahí.
4. **Configurar `.env`**: copia `.env.production.example` como `.env` y completa APP_KEY
   (en local: `php artisan key:generate --show`), APP_URL y los datos de MySQL.
5. **Tablas**: si tu plan tiene SSH: `php artisan migrate --force`. Sin SSH: importa el SQL generado en local con
   `php artisan schema:dump` o corre las migraciones apuntando tu `.env` local a la BD remota (si Hostinger permite acceso remoto).
6. **Cron** (hPanel → Avanzado → Cron Jobs, cada minuto):
   `php /home/USUARIO/domains/DOMINIO/public_html/artisan schedule:run >> /dev/null 2>&1`
   Esto actualiza la tasa USD/Bs cada hora. Sin cron, la app igual la refresca al abrirse (cada 4 h).
7. **Correo** (opcional, para enviar invitaciones por mail en el futuro): MAIL_* con el SMTP de Hostinger.
   Hoy las invitaciones se comparten como enlace (copiar / WhatsApp).
