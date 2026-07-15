# Résultats de tests

## Statut du squelette

| Contrôle | Statut | Preuve / limite |
| --- | --- | --- |
| Catalogue AT-01 à AT-26 | Réussi (statique) | Vérifié avec PHP 8.3.20 : 26 scénarios ordonnés, complets et sans doublon. |
| Syntaxe PHP | Réussi (statique) | PHP 8.3.20 a validé 62 fichiers PHP sous packages/ et tests/. |
| Manifests, ressources et schéma | Réussi (statique) | 7 fichiers XML, le manifeste d’assets JSON et 34 tables SQL ont été analysés; les références de colonnes PHP connues existent dans le DDL. |
| Parité des langues et recherche de secrets | Réussi (statique) | Les clés FR/EN sont symétriques par contexte; aucun motif de valeur d’identifiant/secret n’a été trouvé sous packages/, docs/ et tests/. |
| Catalogue AT-01 à AT-26 | Préparé | Les 26 scénarios sont définis dans Fixtures/acceptance-scenarios.php et vérifiés par AcceptanceCatalogTest. |
| Source PHPUnit | Préparé | Le runner et l’adaptateur d’acceptation sont fournis, sans dépendance ni environnement de test versionné. |
| Liens Markdown internes | Réussi | Les liens relatifs de docs/ et tests/ ont été vérifiés lors de la préparation. |
| Recherche de valeurs de secret dans docs/tests | Réussi | Aucun motif de valeur de token/mot de passe n’a été trouvé dans ces dossiers. |
| Exécution PHPUnit | Non exécutée | PHPUnit, Joomla et une base de test isolée ne sont pas disponibles dans cet espace de travail. |
| AT-01 à AT-26 contre Joomla/Square Sandbox | Non exécutés | Nécessitent l’adaptateur isolé, une base Joomla de test et un environnement Square Sandbox. |

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
