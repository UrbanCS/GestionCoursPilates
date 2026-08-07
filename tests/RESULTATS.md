# Résultats de tests

## Contrôles de la version 1.8.4 — 2026-08-07

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Libellé de l’horaire | Réussi (contrat statique) | La traduction française de `COM_MEMIPILATES_SCHEDULE_FIND_CLASS` est maintenant « Horaire ». |
| Manifestes et ressources | Réussi | Les quatre manifestes déclarent 1.8.4 et le paquet se construit sans erreur. |
| Archive Joomla 1.8.4 | Réussi | ZIP de 294008 octets, SHA-256 `21B18B9B3834F645C6AFFBA2C5B1409ED12A7850DC64CC8E3B3DE5C51074D0F2`. |
| Vérification sur le site réel | Réussi | Le paquet 1.8.4 a été installé sur Joomla; la page publique affiche le titre « Horaire » et ne contient plus « Trouver un cours ». |

### Artefact

- Archive : `dist/pkg_memipilates-1.8.4.zip`
- Taille : `294008` octets
- SHA-256 : `21B18B9B3834F645C6AFFBA2C5B1409ED12A7850DC64CC8E3B3DE5C51074D0F2`

## Contrôles de la version 1.8.3 — 2026-08-06

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Retour après connexion | Réussi (contrat statique) | Les liens de connexion de la réservation et du paiement encodent la séance sélectionnée dans le paramètre Joomla `return`. |
| Checkout de forfait | Réussi (contrat statique) | L’absence de séance demeure acceptée afin de permettre l’achat d’un forfait sans cours sélectionné. |
| Manifestes et ressources | Réussi | Les quatre manifestes déclarent 1.8.3; le manifeste d’assets JSON est valide et le paquet se construit sans erreur. |
| Diff Git | Réussi | `git diff --check` ne signale aucune erreur; les avertissements CRLF de Git sont informatifs. |
| Archive Joomla 1.8.3 | Réussi | ZIP de 294016 octets, SHA-256 `B01F8664009DE0AA260BE6621DA8FFE7C6FA36DE85554B020DA1ACD44D02D4FD`. |
| Vérification sur le site réel | Réussi | Le paquet 1.8.3 a été installé sur Joomla. Pour la séance 30 (« Sol essentiel »), le lien de connexion et la redirection du paiement décodent tous deux vers une URL conservant `session_id=30`; la page authentifiée affiche la séance et son paiement direct. |

### Artefact

- Archive : `dist/pkg_memipilates-1.8.3.zip`
- Taille : `294016` octets
- SHA-256 : `B01F8664009DE0AA260BE6621DA8FFE7C6FA36DE85554B020DA1ACD44D02D4FD`

## Contrôles de la version 1.8.2 — 2026-08-06

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Horaire du studio unique | Réussi (contrat statique) | Le filtre d’emplacement, les raccourcis Mon compte/Acheter un forfait et le nom du studio sont absents du gabarit; la salle demeure disponible. |
| Libellés demandés | Réussi (contrat statique) | Les traductions françaises déclarent « Réservez » et « Cours disponibles seulement ». |
| Administration | Réussi (contrat statique) | Le Catalogue ne propose plus les emplacements; les formulaires de salle conservent l’identifiant technique du studio dans un champ masqué. |
| Manifestes et ressources | Réussi | Les quatre manifestes déclarent 1.8.2; 16 fichiers XML, le manifeste JSON, 12 fichiers INI et 9 fichiers JavaScript sont valides. |
| Diff Git | Réussi | `git diff --check` ne signale aucune erreur; les avertissements CRLF de Git sont informatifs. |
| Archive Joomla 1.8.2 | Réussi | ZIP externe de 9 entrées, taille 293841 octets, SHA-256 `1367C7A343C984F76763467863C6051A1914CC0DCB4FB4BA5D5D414E592D4F75`. |
| Vérification sur le site réel | Réussi | Le paquet 1.8.2 a été installé sur Joomla. L’horaire affiche « RÉSERVEZ » et « Cours disponibles seulement », sans raccourcis de compte/forfait ni référence à l’emplacement. L’administration utilise uniquement Studio/Salle. |
| Données d’exemple | Réussi | Les quatre catégories demandées possèdent un cours visible avec séance fictive; les forfaits de la page Tarifs ont été saisis, dont les deux forfaits mensuels illimités représentés par 1 000 crédits valides 30 jours. |

### Artefact

- Archive : `dist/pkg_memipilates-1.8.2.zip`
- SHA-256 : `1367C7A343C984F76763467863C6051A1914CC0DCB4FB4BA5D5D414E592D4F75`

## Contrôles de la version 1.8.1 — 2026-07-30

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Checkout sans séance | Réussi (contrat statique) | La valeur absente de `session_id` devient explicitement `0`, ce qui laisse le catalogue des forfaits se charger. |
| Erreur de paiement direct | Réussi (contrat statique) | Une séance inexistante produit maintenant le message localisé prévu plutôt que la clé de traduction brute. |
| Manifestes et ressources | Réussi | Les quatre manifestes déclarent 1.8.1; 16 fichiers XML et le manifeste JSON sont valides. |
| Diff Git | Réussi | `git diff --check` ne signale aucune erreur; les avertissements CRLF de Git sont informatifs. |
| Archive Joomla 1.8.1 | Réussi | ZIP externe de 9 entrées, composant de 155 entrées et correctif du checkout confirmé dans l’archive. |
| Vérification sur le site réel | Réussi | Version 1.8.1 installée sur Joomla 6.1/PHP 8.3; le checkout authentifié sans `session_id` affiche le forfait disponible et le bouton de paiement. Une séance inexistante retourne une 404 française contrôlée sans erreur PHP. |

### Artefact

- Archive : `dist/pkg_memipilates-1.8.1.zip`
- Taille : `294265` octets
- SHA-256 : `212ECE9634CF2A2F3F1AC7E9F631A36A231153827E0C77A740ABE3E35C762C54`

## Contrôles locaux de la version 1.8.0 — 2026-07-30

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Catalogue public des cours | Réussi (contrat statique) | La vue publique sélectionne seulement les catégories publiées, non archivées et visibles; chaque carte illustrée pointe vers l’horaire filtré. |
| Filtre et accès de l’horaire | Réussi (contrat statique) | Les niveaux d’accès et la catégorie demandée sont imposés dans les requêtes serveur des séances, du calendrier et des filtres; JavaScript n’est qu’une amélioration d’interface. |
| Images des catégories | Réussi (contrat statique) | Le champ média Joomla est rendu dans le Catalogue et la Mise en route; seuls les chemins canoniques sous `images/memipilates/` avec une extension d’image permise sont acceptés. |
| Manifestes et ressources | Réussi | 16 fichiers XML analysés, manifeste d’assets JSON valide en version 1.8.0 et 16 fichiers INI sans clé dupliquée. |
| JavaScript | Réussi | Les 9 fichiers JavaScript passent `node --check`. |
| Diff Git | Réussi | `git diff --check` ne signale aucune erreur; les avertissements CRLF de Git sont informatifs. |
| Archive Joomla 1.8.0 | Réussi | ZIP externe de 9 entrées, composant de 155 entrées et cinq fichiers 1.8.0 critiques confirmés dans l’archive. |
| Syntaxe PHP locale | Non exécutée | Aucun exécutable PHP n’est disponible localement; l’installation et l’ouverture des vues sur Joomla 6.1/PHP 8.3 servent de validation d’exécution. |
| Vérification sur le site réel | Réussi | Version 1.8.0 installée sur Joomla 6.1/PHP 8.3; le menu COURS affiche les quatre cartes illustrées et chaque lien ouvre l’horaire filtré correspondant sans erreur technique. L’ancienne route de réservation sans séance retourne une 404 française contrôlée. |

### Artefact

- Archive : `dist/pkg_memipilates-1.8.0.zip`
- Taille : `294260` octets
- SHA-256 : `7EB6B903B231B868C492C56AD121811562761BC45DC08D1CA8C41941940AD068`

## Contrôles locaux de la version 1.7.0 — 2026-07-28

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Syntaxe PHP | Réussi (analyse statique) | 113 fichiers PHP du paquet et des tests sont acceptés par une grammaire PHP indépendante. |
| Syntaxe JavaScript | Réussi | Les 9 fichiers JavaScript passent `node --check`. |
| XML, JSON et langues | Réussi | 14 fichiers XML analysés, manifeste d’assets JSON valide et 16 fichiers INI sans clé dupliquée. |
| Paramètres du portail | Réussi (contrat statique) | Les 27 champs rendus ont chacun une règle serveur, y compris le nouveau taux global de taxes. |
| Calcul des taxes | Réussi (revue statique) | Taux `14,975 %` représenté par l’entier `14975`; calcul et arrondi en cents sans flottant; commandes, lignes de commande, affichage et total Square concordants. |
| Horaire hebdomadaire | Réussi (contrat statique) | Semaine lundi–dimanche, modes Semaine/Jour, compteurs filtrés et marqueurs du calendrier mensuel couverts par le contrat. |
| Revue indépendante | Réussi après corrections | La navigation hors de la plage préchargée recharge maintenant une plage fiable; l’édition du catalogue préserve les anciennes colonnes fiscales par entité. |
| Archive Joomla 1.7.0 | Réussi | ZIP externe de 9 entrées, composant de 150 entrées et version imbriquée `1.7.0` confirmée. |
| Vérification sur le site réel | À exécuter | Installer 1.7.0, vider les caches, vérifier l’horaire en vue Semaine/Jour et effectuer une commande Square Sandbox après configuration. |

### Artefact

- Archive : `dist/pkg_memipilates-1.7.0.zip`
- Taille : `288929` octets
- SHA-256 : `3414D7178750C5F2766F8CA38654CA1DEA7CF627EBB87C1C665EF489E3CAF58F`

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
