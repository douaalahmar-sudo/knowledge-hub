<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\KaizenReport;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed roles, one demo login per role, and sample business data.
     *
     * Role names are snake_case to match the Gate definitions in AppServiceProvider
     * (admin / process_owner / operator ...). All demo users share the same store
     * so every role can see the seeded procedures/kaizen data.
     */
    public function run(): void
    {
        // 1. Roles (keys align with authorization gates + the RBAC matrix).
        //
        // NOTE: `responsable_departement` is the first of two approval gates in the
        // procedure-validation workflow (draft -> Responsable de Département (métier)
        // -> Validator (qualité) -> published). It is intentionally a distinct role
        // from `validator`, not an alias — the workflow logic that actually enforces
        // the two-step gate does not exist yet anywhere in this backend (no gate, no
        // CheckRole middleware use, no status-transition code) and still needs to be
        // built; this only seeds the role + demo user for it.
        $roles = [
            'admin'                    => 'Administrateur IT / Sécurité — accès complet',
            'process_owner'            => 'Propriétaire de processus — valide et publie',
            'expert_metier'            => 'Expert métier — rédige les procédures & articles',
            'responsable_departement'  => 'Responsable de Département — 1re validation (métier) des procédures avant contrôle qualité',
            'validator'                => 'Qualité / Conformité — approuve les documents (2e validation)',
            'manager'                  => 'Manager magasin / rayon — supervise les équipes',
            'operator'                 => 'Personnel terrain — consulte & signale (Kaizen)',
            'hr_admin'                 => 'Administrateur RH — traite les demandes RH',
            'hr_user'                  => 'Employé libre-service RH — soumet des demandes',
        ];
        $roleModels = [];
        foreach ($roles as $name => $description) {
            $roleModels[$name] = Role::updateOrCreate(['name' => $name], ['description' => $description]);
        }

        // 2. Tenants
        $hqTenant = Tenant::firstOrCreate(['name' => 'FLESK HQ & Services Centraux']);
        $store101 = Tenant::firstOrCreate(['name' => 'FLESK Store #101 - Tunis']);

        // 3. One demo login per role (password: password123).
        // All roles are seeded in Store #101 EXCEPT `responsable_departement`, which
        // is intentionally seeded on the HQ tenant per the chosen design (tenant-agnostic,
        // "global business validation above store level" rather than scoped like
        // Manager/Operator). See the flag below this array for the consequence of that
        // choice under the current tenant-isolation implementation.
        $demoUsers = [
            ['email' => 'admin@flesk.com',        'name' => 'Sami Ben Ali',    'matricule' => 'KH-ADM-001', 'role' => 'admin',                   'tenant' => 'store'],
            ['email' => 'owner@flesk.com',        'name' => 'Amina Mansour',   'matricule' => 'KH-OWN-001', 'role' => 'process_owner',           'tenant' => 'store'],
            ['email' => 'expert@flesk.com',       'name' => 'Nizar Haddad',    'matricule' => 'KH-EXP-001', 'role' => 'expert_metier',           'tenant' => 'store'],
            ['email' => 'dept.manager@flesk.com', 'name' => 'Rania Sassi',     'matricule' => 'KH-DPT-001', 'role' => 'responsable_departement', 'tenant' => 'hq'],
            ['email' => 'validator@flesk.com',    'name' => 'Leila Trabelsi',  'matricule' => 'KH-VAL-001', 'role' => 'validator',               'tenant' => 'store'],
            ['email' => 'manager@flesk.com',      'name' => 'Karim Bouazizi',  'matricule' => 'KH-MGR-101', 'role' => 'manager',                 'tenant' => 'store'],
            ['email' => 'operator@flesk.com',     'name' => 'Karim Zouari',    'matricule' => 'KH-OPR-101', 'role' => 'operator',                'tenant' => 'store'],
            ['email' => 'hr.admin@flesk.com',     'name' => 'Fatma Gharbi',    'matricule' => 'KH-HRA-001', 'role' => 'hr_admin',                'tenant' => 'store'],
            ['email' => 'hr.user@flesk.com',      'name' => 'Youssef Nasri',   'matricule' => 'KH-HRU-001', 'role' => 'hr_user',                 'tenant' => 'store'],
        ];

        $users = [];
        foreach ($demoUsers as $u) {
            $tenant = $u['tenant'] === 'hq' ? $hqTenant : $store101;
            $users[$u['role']] = User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name'      => $u['name'],
                    'matricule' => $u['matricule'],
                    'password'  => bcrypt('password123'),
                    'tenant_id' => $tenant->id,
                    'role_id'   => $roleModels[$u['role']]->id,
                ]
            );
        }

        // FLAG: `BelongsToTenant` (app/Traits/BelongsToTenant.php) scopes every query
        // by strict `tenant_id` equality. Because dept.manager@flesk.com is seeded on
        // the HQ tenant (not Store #101), they will NOT see Store #101's procedures
        // through the existing global scope — so as seeded, this account cannot yet
        // perform the "1st validation" step on Store #101 procedures. Making the HQ
        // scope actually see store-level data requires extending BelongsToTenant (e.g.
        // a hierarchical/"HQ sees all child stores" rule) — not implemented here.

        // 4. Sample procedures (owned by the process owner, in Store #101).
        $procedureSeeds = [
            ['reference_code' => 'PR-2026-010', 'name' => 'Procédure Ouverture & Fermeture de Caisse',        'module' => 'Operations',     'status' => 'Validé'],
            ['reference_code' => 'PR-2026-011', 'name' => 'Consignes de Sécurité - Réception Marchandises',   'module' => 'Logistique',     'status' => 'En attente'],
            ['reference_code' => 'PR-2026-012', 'name' => 'Gestion des Retours & Remboursements Client',      'module' => 'Service Client', 'status' => 'Validé'],
            ['reference_code' => 'PR-2026-013', 'name' => 'Inventaire Tournant Mensuel',                      'module' => 'Stock',          'status' => 'Validé'],
            ['reference_code' => 'PR-2026-014', 'name' => 'Protocole Hygiène & Sécurité Alimentaire',         'module' => 'Conformité',     'status' => 'En attente'],
        ];

        $procedures = [];
        foreach ($procedureSeeds as $p) {
            $procedures[$p['reference_code']] = Procedure::firstOrCreate(
                ['reference_code' => $p['reference_code']],
                [
                    'tenant_id'  => $store101->id,
                    'name'       => $p['name'],
                    'module'     => $p['module'],
                    'status'     => $p['status'],
                    'created_by' => $users['process_owner']->id,
                ]
            );
        }

        // 5. Knowledge Base articles, one per official category (+ an extra policy doc).
        // `slug` is the route key (see Article::getRouteKeyName) so it's the natural
        // uniqueness key for firstOrCreate here.
        $articleSeeds = [
            [
                'slug'     => 'bienvenue-chez-flesk-guide-de-demarrage',
                'title'    => 'Bienvenue chez FLESK — Guide de démarrage',
                'summary'  => 'Tout ce qu’un nouveau collaborateur doit savoir pour bien démarrer.',
                'content'  => '<h2>Bienvenue !</h2><p>Ce guide couvre vos premiers pas : accès, badges, et contacts utiles.</p>',
                'category' => 'onboarding_guides',
                'tags'     => ['onboarding', 'accueil'],
                'author'   => 'hr_admin',
            ],
            [
                'slug'     => 'nouvelle-politique-de-conges-2026',
                'title'    => 'Nouvelle politique de congés 2026',
                'summary'  => 'Mise à jour des règles de congés payés et RTT applicables cette année.',
                'content'  => '<h2>Congés 2026</h2><p>Les nouvelles règles entrent en vigueur au 1er janvier.</p>',
                'category' => 'policies_guidelines',
                'tags'     => ['rh', 'congés', 'politique'],
                'author'   => 'hr_admin',
            ],
            [
                'slug'     => 'annonce-ouverture-store-101-tunis',
                'title'    => 'Annonce : ouverture du Store #101 à Tunis',
                'summary'  => 'Notre nouveau magasin ouvre ses portes ce mois-ci.',
                'content'  => '<p>Nous sommes fiers d’annoncer l’ouverture du Store #101 à Tunis.</p>',
                'category' => 'news_announcements',
                'tags'     => ['annonce', 'magasin'],
                'author'   => 'admin',
            ],
            [
                'slug'     => 'formulaires-rh-mode-demploi',
                'title'    => 'Formulaires RH : mode d’emploi',
                'summary'  => 'Où trouver et comment soumettre vos documents RH courants.',
                'content'  => '<h2>Formulaires disponibles</h2><p>Fiche de paie, attestation de travail, demande de congé.</p>',
                'category' => 'hr_documentation',
                'tags'     => ['rh', 'formulaires', 'documents'],
                'author'   => 'hr_admin',
            ],
            [
                'slug'     => 'charte-informatique-bonnes-pratiques-securite',
                'title'    => 'Charte informatique & bonnes pratiques de sécurité',
                'summary'  => 'Règles d’usage des outils numériques et protection des données.',
                'content'  => '<h2>Sécurité au quotidien</h2><p>Verrouillez votre session, signalez les emails suspects.</p>',
                'category' => 'policies_guidelines',
                'tags'     => ['sécurité', 'informatique', 'conformité'],
                'author'   => 'admin',
            ],
        ];

        foreach ($articleSeeds as $a) {
            Article::firstOrCreate(
                ['slug' => $a['slug']],
                [
                    'tenant_id'            => $store101->id,
                    'author_id'            => $users[$a['author']]->id,
                    'title'                => $a['title'],
                    'summary'              => $a['summary'],
                    'content'              => $a['content'],
                    'category'             => $a['category'],
                    'tags'                 => $a['tags'],
                    'status'               => 'published',
                    'published_at'         => now(),
                    'reading_time_minutes' => 2,
                ]
            );
        }

        // 6. Kaizen reports across all three criticality levels.
        $kaizenSeeds = [
            [
                'description'  => 'Ecart de 15 min constaté lors du transfert d’ouverture de caisse.',
                'reference'    => 'PR-2026-010',
                'criticality'  => 'Moyenne',
                'status'       => 'Ouvert',
                'user'         => 'operator',
            ],
            [
                'description'  => 'Proposition d’ajout d’une checklist de contrôle à la réception.',
                'reference'    => 'PR-2026-011',
                'criticality'  => 'Faible',
                'status'       => 'Résolu',
                'user'         => 'operator',
            ],
            [
                'description'  => 'Rupture de la chaîne du froid détectée sur le rayon frais pendant 40 minutes.',
                'reference'    => 'PR-2026-014',
                'criticality'  => 'Critique',
                'status'       => 'Ouvert',
                'user'         => 'manager',
            ],
        ];

        foreach ($kaizenSeeds as $k) {
            KaizenReport::firstOrCreate(
                ['description' => $k['description']],
                [
                    'tenant_id'    => $store101->id,
                    'procedure_id' => $procedures[$k['reference']]->id,
                    'user_id'      => $users[$k['user']]->id,
                    'criticality'  => $k['criticality'],
                    'status'       => $k['status'],
                ]
            );
        }
    }
}
