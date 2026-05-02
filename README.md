# Qiwam ERP - Backend 🚀

Le moteur central de **Qiwam ERP**, une solution de gestion d'entreprise multi-tenant conçue pour la performance et la scalabilité. Construit avec **Laravel 12** et **PHP 8.3**.

## 🌟 Points Forts
- **Architecture Multi-tenant** : Isolation totale des données pour chaque entreprise cliente.
- **Gestion POS (Point de Vente)** : Backend optimisé pour des transactions rapides.
- **Facturation PDF** : Génération automatique de factures professionnelles via DomPDF.
- **Gestion des Stocks** : Synchronisation en temps réel lors des ventes et réceptions fournisseurs.
- **Sécurité** : Authentification stateless via Laravel Sanctum et gestion fine des rôles (Super Admin, Admin, Agent).

## 🛠️ Stack Technique
- **Framework** : Laravel 12.x
- **Langage** : PHP 8.3+
- **Base de données** : PostgreSQL / MySQL
- **Authentification** : Laravel Sanctum (Tokens API)
- **PDF** : Barryvdh/Laravel-DomPDF
- **DevOps** : Docker & Docker Compose inclus

## ⚙️ Installation Rapide

1. **Cloner le dépôt**
2. **Installer les dépendances**
   ```bash
   composer install
   ```
3. **Configurer l'environnement**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
4. **Base de données** (Configurez vos accès dans le `.env`)
   ```bash
   php artisan migrate --seed
   ```
5. **Lien symbolique pour les images**
   ```bash
   php artisan storage:link
   ```
6. **Lancer le serveur**
   ```bash
   php artisan serve
   ```

## 📂 Structure du projet
- `app/Http/Controllers/Api/V1` : Contrôleurs de l'API versionnée.
- `app/Models` : Modèles Eloquent avec événements (booted) pour la synchronisation des stats.
- `database/migrations` : Schéma de base de données optimisé (Tenants, Products, Orders, etc.).
- `resources/views/pdf` : Templates Blade pour les factures.

---
© 2026 Qiwam ERP.
