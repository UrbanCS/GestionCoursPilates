# Plan de tests et matrice d’acceptation

## Objectif

Les tests doivent démontrer les règles métier critiques du paquet Joomla, pas seulement l’affichage de boutons. La preuve de test combine :

- tests unitaires de calculs de délai, transitions d’état, registres et validation;
- tests d’intégration avec une base Joomla isolée pour transactions, contraintes et services;
- tests d’acceptation HTTP/navigateur pour ACL, routes, paiement Sandbox et borne;
- contrôles manuels ciblés pour matériel HID, caméra, accessibilité, template et cPanel.

Le squelette automatisé est sous [tests/](../tests/README.md). Il ne contient aucun secret, compte réel, QR réel ou accès à la production.

## Environnement et données de test

Utiliser une instance Joomla de test, une base dédiée et des comptes fictifs : un Super administrateur, un gestionnaire, un employé sans dérogation, un employé avec dérogation et plusieurs clients. Les fixtures doivent inclure :

- un cours avec places disponibles, un cours avec une dernière place et un cours complet;
- une réservation avec crédit, une commande non payée et des scénarios Square Sandbox;
- des règles d’annulation de 12 heures et de changement d’heure America/Toronto;
- une file d’attente à plusieurs positions et une offre proche de l’expiration;
- un QR valide, un QR révoqué et des tokens de format invalide;
- un lecteur HID simulé avec suffixe Entrée, une caméra simulée et des données de recherche manuelle;
- des identifiants d’événements Square répétés et des clés d’idempotence répétées.

Définir les identifiants, dates et montants à l’intérieur de chaque scénario. Ne jamais pointer les tests automatisés vers la production ni vers une base partagée.

## Matrice d’acceptation obligatoire

| ID | Scénario | Couche principale | Résultat attendu |
| --- | --- | --- | --- |
| AT-01 | Client réserve une place disponible | Intégration + E2E | Une seule réservation confirmed, capacité décrémentée et trace de registre appropriée. |
| AT-02 | Deux clients tentent la dernière place | Intégration transactionnelle | Un seul confirmed; l’autre reçoit complet/liste d’attente sans dépassement. |
| AT-03 | Client avec crédit réserve sans paiement | Intégration | Crédit consommé une fois, pas de demande Square, réservation confirmed. |
| AT-04 | Client sans crédit achète un forfait | Sandbox + E2E | Commande/payement rapprochés, forfait et crédits attribués une fois. |
| AT-05 | Paiement échoué | Sandbox + intégration | Aucune réservation confirmed, aucun crédit/point attribué. |
| AT-06 | Même webhook reçu deux fois | Intégration | Premier événement appliqué; second sans effet métier supplémentaire. |
| AT-07 | Annulation à plus de 12 h | Unitaire + intégration | Statut cancelled_on_time et restauration unique du crédit. |
| AT-08 | Annulation tardive | Unitaire + intégration | Statut cancelled_late, aucune restauration automatique. |
| AT-09 | Annulation par le studio | Intégration | Réservations annulées, crédits restaurés, attente fermée, notifications/audit créés; remboursement distinct. |
| AT-10 | Cours complet et inscription à l’attente | Intégration + E2E | Client waitlisted, position chronologique, aucun crédit consommé. |
| AT-11 | Une place libérée va au premier client | Intégration + cron | Une seule offre temporaire au premier admissible avec délai/audit. |
| AT-12 | Offre expirée va au suivant | Intégration + cron | Offre initiale expire une fois, client suivant reçoit l’offre. |
| AT-13 | QR valide confirme la présence | Intégration + E2E | Présence liée à réservation/cours, résultat borne attendu. |
| AT-14 | QR révoqué refusé | Unitaire + intégration | Aucun changement de présence, points ou réservation. |
| AT-15 | Deuxième scan du même QR | Intégration | Aucune deuxième présence ni modification de réservation. |
| AT-16 | Scan ajoute les points une seule fois | Intégration transactionnelle | Présence et écriture points atomiques, répétition inoffensive. |
| AT-17 | Paiement ajoute les points une seule fois | Intégration transactionnelle | Points liés au paiement/commande, webhook/retry sans doublon. |
| AT-18 | Lecteur HID termine par Entrée | Navigateur/unit JS | L’entrée complète déclenche une requête unique après Entrée. |
| AT-19 | Scan incomplet refusé | Unitaire + navigateur | Format/longueur invalide, aucun appel métier ou effet serveur. |
| AT-20 | Borne reprend le focus | Navigateur manuel/automatisé | Après résultat/erreur, le champ de scan revient en état prêt. |
| AT-21 | Mode test lecteur | Navigateur | Mesures locales affichées; aucune requête, présence, point ou token persisté. |
| AT-22 | Employé sans droit de dérogation | ACL + E2E | Le serveur refuse l’override et ne crée aucun effet. |
| AT-23 | Client tente de lire un autre client | ACL + HTTP | 403/absence de donnée, y compris routes de reçu/export. |
| AT-24 | Cron relancé | Intégration + CLI | Deux exécutions ne créent ni séance, ni offre, ni notification dupliquée. |
| AT-25 | Installation Joomla non destructive | Préproduction manuelle | Pages, template, menus et extensions existants restent fonctionnels. |
| AT-26 | Désinstallation selon politique | Préproduction manuelle | Données d’exploitation conservées/traitées selon la politique, sans suppression implicite inattendue. |

## Tests complémentaires de qualité

| Domaine | Contrôles minimaux |
| --- | --- |
| ACL | Test positif/négatif de chaque action sensible et du filtrage de portée par user_id/instructeur. |
| Sécurité | CSRF, XSS sortant, paramètres SQL, limitation QR, expiration/révocation, masquage des logs, signature webhook. |
| Accessibilité | Navigation clavier, focus visible, libellés, erreurs annoncées, contraste, vue sans animation et lecteur d’écran sur réservation/borne. |
| Responsive | Horaire, paiement, espace client et borne sur téléphone, tablette et ordinateur. |
| Fuseaux horaires | Annulation et rappels autour du passage heure d’été/heure d’hiver America/Toronto. |
| Paiements | Montants en cents, taxe/promo, refus, timeout, retry, remboursement administratif et rapprochement. |
| Charge/concurrence | Dernière place, scans rapides, offres d’attente et cron chevauchants. |
| Déploiement | Installation/upgrade/rollback préproduction, cache Joomla, cPanel, journal et cron. |

## Exécution et résultats

Le projet doit ajouter PHPUnit comme dépendance de développement avant d’exécuter le squelette. Une fois l’autoloader et l’adaptateur d’environnement configurés, exécuter :

~~~sh
vendor/bin/phpunit -c tests/phpunit.xml.dist
~~~

Pour une exécution d’acceptation, définir uniquement dans le système de CI ou un fichier local ignoré :

~~~text
MEMIPILATES_ACCEPTANCE_DRIVER=Nom\De\LAdaptateurDeTest
MEMIPILATES_TEST_BASE_URL=https://joomla-test.example.invalid
MEMIPILATES_TEST_TIMEZONE=America/Toronto
~~~

Ne mettre ni identifiant Square, ni mot de passe, ni URL Production dans phpunit.xml.dist ou dans une fixture versionnée. Le driver doit refuser une URL Production par liste d’autorisation explicite.

Consigner chaque campagne avec ce modèle :

| Version paquet | Joomla/PHP | Environnement | Date/heure | Exécutant | AT réussis | AT échoués/bloqués | Lien vers preuve |
| --- | --- | --- | --- | --- | --- | --- | --- |
| À compléter | À compléter | Préproduction | À compléter | À compléter | À compléter | À compléter | Journal/capture protégée |

Un test skipped dû à l’absence d’adaptateur ou d’environnement n’est pas un test réussi. Avant la publication, tous les AT-01 à AT-26 doivent être réussis ou faire l’objet d’une décision de risque écrite approuvée.
