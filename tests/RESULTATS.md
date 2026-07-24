# Résultats de tests

## Contrôles locaux de la version 1.6.1 — 2026-07-24

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Paramètres frontaux | Réussi (contrat statique) | Les 26 paramètres autorisés possèdent chacun un champ frontal explicite; l’ancien rendu de groupes vides a été retiré. |
| Sécurité Square | Réussi (contrat statique) | Les deux secrets sont toujours vidés avant rendu et conservés lorsqu’ils sont soumis vides; l’enregistrement reste limité à `core.admin` et protégé par CSRF. |
| Mise en page responsive | Réussi (statique) | Calcul de largeur en `border-box`, contenu borné, filtres sans marges négatives, boutons repliables et tableaux à défilement horizontal interne. |
| Syntaxe PHP | Réussi (analyse statique) | 112 fichiers PHP du paquet et des tests analysés sans erreur avec une grammaire PHP 8.3 indépendante. |
| JavaScript, XML et assets | Réussi (statique) | JavaScript sans erreur de syntaxe, XML analysé et manifeste d’assets JSON valide en version 1.6.1. |
| Traductions des paramètres | Réussi (statique) | Les 70 clés de langue utilisées par l’écran Paramètres sont résolues dans les fichiers FR du site ou de l’administration. |
| Construction reproductible | Réussi | Deux constructions successives ont produit 284532 octets et le SHA-256 `32EAD3F9046430FD2E004D95C6C53FC1AC2F4D4A0BE3BD29F97D3CB2EE205C16`. |
| Archive Joomla 1.6.1 | Réussi (statique) | ZIP externe de 9 entrées, composant de 149 entrées et quatre fichiers correctifs critiques confirmés dans l’archive. |
| Vérification sur le site réel | À exécuter | Installer 1.6.1, vider le cache Joomla/navigateur, vérifier les colonnes de droite et enregistrer un paramètre non sensible. |

## Contrôles locaux de la version 1.6.0 — 2026-07-24

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Manifestes XML | Réussi (statique) | 14 fichiers XML analysés; les quatre manifestes d’extension déclarent 1.6.0 et le type de menu Gestion du studio est présent. |
| Assets Joomla | Réussi (statique) | JSON valide; la feuille de style responsive du portail est déclarée et incluse dans l’archive. |
| Langues | Réussi (statique) | Les clés FR/EN du site, des menus et de l’administration sont symétriques. |
| Portail frontal | Réussi (contrat statique) | Onze vues et onze gabarits présents; les écrans opérationnels réutilisent les vues et services administratifs. |
| ACL et écritures | Réussi (contrat statique) | Carte ACL centrale, redirection de connexion, refus 403, contrôles ACL par domaine et CSRF des écritures présents. |
| Paramètres Square | Réussi (contrat statique) | Écran limité à `core.admin`; secrets non rendus, champs vides conservés, URL webhook HTTPS validée et audit sans secret. |
| Schéma et mise à jour | Réussi (statique) | Migration non destructive 1.6.0 présente; aucune table métier supprimée ou remplacée. |
| Syntaxe PHP | Réussi (analyse statique) | 112 fichiers PHP du paquet et des tests analysés sans erreur avec une grammaire PHP 8.3 indépendante; `php -l` n’est pas disponible localement. |
| JavaScript | Réussi (statique) | Tous les fichiers JavaScript passent le contrôle de syntaxe Node.js. |
| Catalogue d’acceptation | Réussi (statique) | 32 scénarios AT-01 à AT-32 ordonnés et sans doublon; AT-30 à AT-32 couvrent le portail frontal et les secrets Square. |
| Construction reproductible | Réussi | Deux constructions successives ont produit 281669 octets et le SHA-256 `B553B0B7C469E913DC03180436610D85D69B0B6E18AA05BD2D04B61571B105BF`. |
| Archive Joomla 1.6.0 | Réussi (statique) | ZIP externe de 9 entrées et trois archives enfants de 148/8/2 entrées; tous les fichiers critiques du portail sont présents. |
| Diff Git | Réussi (statique) | `git diff --check` ne signale aucune erreur; les avertissements CRLF de Git sont informatifs. |
| PHPUnit / exécution Joomla | Non exécuté | Aucun exécutable PHP, serveur Joomla ou moteur MySQL/MariaDB n’est disponible dans cet espace local. |
| Navigation réelle dans le template | Non exécutée | L’installation 1.6.0 et le test connecté sur le site réel restent requis après téléversement. |
| Square Sandbox | Non exécuté | Les renseignements Square de la cliente ne sont pas encore disponibles. |

## Contrôles locaux de la version 1.5.5 — 2026-07-23

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Manifestes XML | Réussi (statique) | 13 fichiers XML analysés; les quatre manifestes d’extension déclarent 1.5.5. |
| Assets Joomla | Réussi (statique) | JSON valide, 10 assets déclarés, 10 fichiers locaux présents et version du checkout actualisée. |
| Langues | Réussi (statique) | 8 paires FR/EN symétriques, sans clé dupliquée. |
| Schéma et mise à jour | Réussi (statique) | 34 tables d’installation et migration non destructive 1.5.5 présentes. |
| Syntaxe PHP | Réussi (analyse statique) | 81 fichiers PHP analysés sans erreur avec une grammaire PHP 8.3 indépendante; `php -l` n’est pas disponible localement. |
| JavaScript | Réussi (statique) | Les 9 fichiers passent le contrôle de syntaxe Node.js. |
| Paiement direct | Réussi (contrat statique) | Création d’une commande de séance, retenue atomique, confirmation après paiement, échec/expiration avec libération et traitement planifié reliés. |
| Contrat Square actuel | Réussi (statique) | La tokenisation transmet montant, devise, intention et contexte client; le serveur conserve la clé d’idempotence, les montants en cents et la validation HMAC du webhook. |
| Catalogue d’acceptation | Réussi (statique) | 29 scénarios AT-01 à AT-29 ordonnés et sans doublon; AT-28 et AT-29 couvrent le paiement direct et la libération de sa retenue. |
| Construction reproductible | Réussi | Deux constructions successives ont produit 265742 octets et le SHA-256 `E899B8CD8AAFC6A683A2A67B7FCF79753710D6D1852B2103CA77360389AE72CE`. |
| Archive Joomla 1.5.5 | Réussi (statique) | ZIP externe de 9 entrées et trois archives enfants de 115/8/2 entrées; chemins sûrs et fichiers 1.5.5 critiques présents. |
| Recherche de secrets et diff | Réussi (statique) | Aucun motif de secret concret; `git diff --check` sans erreur. |
| PHPUnit / exécution Joomla | Non exécuté | Aucun exécutable PHP, serveur Joomla ou moteur MySQL/MariaDB n’est disponible dans cet espace local. |
| Square Sandbox | Non exécuté | Les identifiants et l’instance HTTPS de préproduction ne sont pas disponibles localement. |

## Contrôles locaux de la version 1.5.4 — 2026-07-23

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Manifestes XML | Réussi (statique) | 13 fichiers XML analysés; les quatre manifestes d’extension déclarent 1.5.4. |
| Assets Joomla | Réussi (statique) | JSON valide, 10 assets déclarés et 10 fichiers locaux présents. |
| Langues | Réussi (statique) | 8 paires FR/EN symétriques, sans clé dupliquée. |
| Schéma et mise à jour | Réussi (statique) | 34 tables d’installation et migration non destructive 1.5.4 présentes. |
| JavaScript | Réussi (statique) | Les 9 fichiers, y compris le worker QR au format module, ont une syntaxe valide. |
| Catalogue d’acceptation | Réussi (statique) | 27 scénarios AT-01 à AT-27 ordonnés et sans doublon; AT-27 couvre la réinscription à la liste d’attente. |
| Construction reproductible | Réussi | Deux constructions successives ont produit 257800 octets et le SHA-256 `D840FA6C085DEF30D816923BCCFC4198361D5648A4F7B2CBBFC88321D550A771`. |
| Archive Joomla 1.5.4 | Réussi (statique) | ZIP externe de 9 entrées, trois archives enfants présentes et chemins POSIX. |
| Recherche de secrets et diff | Réussi (statique) | Aucun motif de secret concret; `git diff --check` sans erreur. |
| Syntaxe PHP / PHPUnit | Non exécutée dans cette campagne | Aucun exécutable PHP ni PHPUnit n’est disponible dans cet espace local. |
| Joomla/MySQL/Square Sandbox | Non exécuté | L’instance de préproduction, la base et les identifiants Sandbox ne sont pas disponibles localement. |

## Contrôles locaux de la version 1.5.0 — 2026-07-22

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Manifestes XML | Réussi (statique) | 13 fichiers XML du paquet et de ses extensions enfants analysés. |
| Assets Joomla | Réussi (statique) | JSON valide, 10 assets uniques, dépendances internes et fichiers locaux présents. |
| Langues | Réussi (statique) | 8 paires FR/EN symétriques, sans clé dupliquée; les 539 références `COM_MEMIPILATES_*` utilisées ont une définition. |
| Schéma et mise à jour | Réussi (statique) | 34 tables d’installation; migration 1.5.0 non destructive avec les colonnes de reprise des courriels et d’unicité QR. |
| Recherche de secrets | Réussi (statique) | 144 fichiers de code, configuration et documentation inspectés; aucun motif de secret concret détecté. |
| Diff Git | Réussi (statique) | `git diff --check` ne signale aucune erreur d’espace ou de conflit. |
| Archive Joomla 1.5.0 | Réussi (statique) | ZIP externe de 9 entrées et 3 ZIP enfants (110/8/2 entrées), chemins POSIX, manifestes 1.5.0 et fichiers de migration/services/assets critiques présents. SHA-256 `2A522C8E32165733EBA11159C76A190EFCBF91C8A00E723272AFE2D369B5CC96`. |
| Syntaxe PHP / exécution Joomla | Non exécutée dans cette campagne | Aucun exécutable PHP, serveur Joomla ou moteur MySQL/MariaDB n’est disponible dans cet espace local. La validation préproduction reste obligatoire. |

## Statut du squelette

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Catalogue AT-01 à AT-32 | Préparé | Les 32 scénarios sont définis dans Fixtures/acceptance-scenarios.php et vérifiés par AcceptanceCatalogTest. |
| Syntaxe PHP | Analysée statiquement pour 1.6.0 | Une grammaire PHP 8.3 indépendante accepte 112 fichiers du paquet et des tests; l’exécutable PHP demeure indisponible. |
| Manifests, ressources et schéma | Réussi (statique) | 7 fichiers XML, le manifeste d’assets JSON et 34 tables SQL ont été analysés; les références de colonnes PHP connues existent dans le DDL. |
| Parité des langues et recherche de secrets | Réussi (statique) | Les clés FR/EN sont symétriques par contexte; aucun motif de valeur d’identifiant/secret n’a été trouvé sous packages/, docs/ et tests/. |
| Source PHPUnit | Préparé | Le runner et l’adaptateur d’acceptation sont fournis, sans dépendance ni environnement de test versionné. |
| Liens Markdown internes | Réussi | Les liens relatifs de docs/ et tests/ ont été vérifiés lors de la préparation. |
| Recherche de valeurs de secret dans docs/tests | Réussi | Aucun motif de valeur de token/mot de passe n’a été trouvé dans ces dossiers. |
| Exécution PHPUnit | Non exécutée | PHPUnit, Joomla et une base de test isolée ne sont pas disponibles dans cet espace de travail. |
| AT-01 à AT-32 contre Joomla/Square Sandbox | Non exécutés | Nécessitent l’adaptateur isolé, une base Joomla de test et un environnement Square Sandbox. |

**Aucun scénario métier ne doit être considéré comme réussi sur la seule base de ce fichier.** Un statut skipped par absence d’adaptateur est incomplet, pas réussi.

## À renseigner pour une campagne

| Version paquet | Commit/révision | Joomla | PHP | Base | Environnement | Date | Exécutant |
| --- | --- | --- | --- | --- | --- | --- |
| À compléter | À compléter | À compléter | À compléter | À compléter | Préproduction isolée | À compléter | À compléter |

| Groupe | Commande/procédure | Passé | Échoué | Skipped | Preuve protégée |
| --- | --- | --- | --- | --- | --- |
| Unitaires | vendor/bin/phpunit -c tests/phpunit.xml.dist --testsuite "Memi Pilates" | À compléter | À compléter | À compléter | À compléter |
| Intégration | Driver Joomla/base isolée | À compléter | À compléter | À compléter | À compléter |
| Acceptance | Driver HTTP/navigateur/Sandbox | À compléter | À compléter | À compléter | À compléter |
| Borne Mac | HID USB, HID Bluetooth, caméra et recherche manuelle | À compléter | À compléter | Sans objet | À compléter |
| Déploiement | Installation, upgrade et rollback préproduction | À compléter | À compléter | Sans objet | À compléter |

Consigner les IDs Square de test, des captures et les journaux uniquement dans un emplacement protégé. Ne pas y inclure de token QR complet, secret, numéro de carte ou donnée personnelle réelle.
