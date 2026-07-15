# Tests Memi Pilates

Ce dossier fournit un squelette PHPUnit pour les règles d’acceptation de com_memipilates. Il est volontairement découplé de toute production : aucun identifiant, QR, compte Square, base ou URL réelle n’est versionné ici.

## Structure

| Chemin | Utilité |
| --- | --- |
| phpunit.xml.dist | Configuration reproductible sans secret. |
| bootstrap.php | Charge l’autoloader local lorsqu’il existe et fixe le fuseau de test. |
| Support/AcceptanceDriver.php | Contrat d’un adaptateur qui pilote Joomla isolé, HTTP ou navigateur. |
| Support/AcceptanceResult.php | Résultat structuré d’un scénario avec résumé et éléments de preuve nettoyés. |
| Support/AcceptanceTestCase.php | Chargement sécurisé de l’adaptateur depuis une variable d’environnement. |
| Acceptance/CriticalAcceptanceTest.php | Un test par scénario AT-01 à AT-26. |
| Contract/AcceptanceCatalogTest.php | Vérifie que le catalogue de scénarios reste complet et sans doublon. |
| Fixtures/acceptance-scenarios.php | Métadonnées versionnées des 26 scénarios obligatoires. |

## Installer le runner

Ajouter PHPUnit comme dépendance de développement au manifeste Composer de niveau projet lorsqu’il sera créé, puis installer les dépendances de développement. Le dossier ne crée pas lui-même de manifeste afin de ne pas imposer un gestionnaire de dépendances ou une version PHP au paquet Joomla.

~~~sh
vendor/bin/phpunit -c tests/phpunit.xml.dist
~~~

Sans adaptateur, les tests d’acceptation sont **skipped**, intentionnellement : ce statut indique que le runner n’est pas relié à une instance Joomla de test, et non que le scénario a réussi.

## Brancher un adaptateur

L’adaptateur doit implémenter MemiPilates\Tests\Support\AcceptanceDriver et être chargé par l’autoloader de développement. Définir uniquement dans CI ou dans un fichier local ignoré :

~~~text
MEMIPILATES_ACCEPTANCE_DRIVER=Tests\Integration\MemiPilatesJoomlaDriver
MEMIPILATES_TEST_BASE_URL=https://joomla-test.example.invalid
MEMIPILATES_TEST_TIMEZONE=America/Toronto
~~~

Le driver doit :

1. refuser les hôtes Production et les bases non dédiées;
2. réinitialiser/transactionner ses fixtures entre scénarios;
3. créer seulement des utilisateurs et données fictifs;
4. simuler Square Sandbox et les callbacks répétés;
5. fournir des preuves nettoyées sans QR, token, secret ou numéro de carte;
6. exercer les requêtes concurrentes et les tâches CLI nécessaires;
7. retourner un échec explicite plutôt qu’un faux succès si l’environnement est incomplet.

La matrice détaillée et les contrôles manuels se trouvent dans [docs/plan-tests.md](../docs/plan-tests.md).
