# Guide administrateur

## Avant d’ouvrir les réservations

Un gestionnaire configure les données dans cet ordre afin d’éviter les séances impossibles à réserver :

1. Emplacements et salles, avec leur capacité réelle et leur fuseau.
2. Instructeurs, liés à leur compte Joomla existant lorsque le rôle doit être limité à ses propres séances.
3. Types de cours, puis cours : durée, prix en cents, crédits requis, capacité, salle, instructeur, fenêtre d’inscription et publication.
4. Règles de récurrence et séances générées; vérifier qu’aucun doublon de créneau ou de salle n’existe.
5. Forfaits, restrictions, dates d’expiration, taxes et points bonus.
6. Promotions avec dates, limites et offres applicables.
7. Modèles courriel, règles de rappel, délai d’annulation, liste d’attente et fidélité.
8. Menus publics, niveaux d’accès et groupes ACL.

Chaque réglage doit être testé avec un compte client fictif avant publication. La page Options regroupe les réglages et les secrets Square : elle est donc réservée aux Super administrateurs disposant de core.admin.

## Mise en route initiale

Après l’installation, un Super administrateur ouvre **Composants → Memi Pilates → Mise en route** et crée le catalogue dans cet ordre. Cet écran initial combine tous les domaines; le personnel délégué utilise ensuite l’écran **Catalogue**, filtré selon ses droits :

1. Emplacement;
2. Salle;
3. Instructeur;
4. Type de cours;
5. Cours;
6. Séance unique ou horaire hebdomadaire;
7. Forfait de cours.

L’enregistrement d’un horaire hebdomadaire génère immédiatement les séances futures selon l’horizon configuré dans les Options. Une séance publiée future est nécessaire avant que l’horaire public puisse afficher un cours. L’écran **Catalogue** permet ensuite de modifier ou de retirer de la vente les emplacements, salles, instructeurs, types de cours, cours, horaires récurrents et forfaits. Les séances ponctuelles se créent dans Catalogue; l’annulation d’une séance existante se fait dans **Séances** afin de préserver l’historique client.

## Gestion quotidienne

| Activité | Action sécurisée |
| --- | --- |
| Catalogue courant | Utiliser **Catalogue** pour modifier les cours et forfaits, ou retirer un élément qui n’a pas de dépendance active. Créer les séances ponctuelles et les horaires récurrents depuis ce même écran. |
| Créer un client | Utiliser **Clients → Créer un client**. Transmettre le mot de passe temporaire par un canal sécurisé, puis demander à la cliente de le changer. |
| Inscription manuelle | Utiliser **Réservations → Inscrire un client**. Choisir « crédit » ou « gratuit » et ajouter une note si nécessaire. |
| Promotions et fidélité | Utiliser **Promotions et fidélité** pour créer les codes, limites, offres de forfait et les récompenses de points. |
| Suivre les paiements | Utiliser **Paiements** pour rapprocher les commandes locales et les états Square; les numéros complets de carte ne sont jamais affichés. |
| Ajouter/modifier un cours | Vérifier capacité, salle, instructeur, période de réservation et publication; conserver une note interne seulement si nécessaire. |
| Annuler une séance | Utiliser l’action d’annulation studio; elle annule les réservations, restaure les crédits selon règle, ferme l’attente et notifie. Un remboursement Square reste explicite. |
| Inscrire manuellement un client | Rechercher le bon compte Joomla, confirmer son identité, choisir la séance, appliquer crédit/promo/paiement selon les droits et ajouter une justification. |
| Ajuster un crédit ou des points | Ajouter un mouvement de registre avec raison et acteur. Ne jamais modifier un solde ou une écriture historique directement. |
| Gérer une attente | Vérifier la position, l’offre active et le délai. Utiliser la promotion manuelle seulement si la politique le permet. |
| Vérifier un paiement | Rapprocher la commande locale, l’ID Square, le montant et l’état avant toute action. |
| Rembourser | Action explicite avec payments.refund, justification, audit et rapprochement; ne suppose pas une restauration automatique de crédit. |
| Exporter | Limiter les colonnes/filtres au besoin légitime, appliquer export.data, protéger le fichier et consigner l’opération. |

## Configuration Square

Seuls les Super administrateurs disposant de core.admin peuvent modifier l’environnement Square. Commencer en Sandbox, vérifier le webhook signé et les paiements de test, puis suivre [Square](square.md) pour le passage Production.

Les champs access token et clé de signature sont des secrets. Les saisir directement depuis le gestionnaire de secrets approuvé; ne pas les copier dans un ticket, un navigateur partagé, un tableur ou une exportation Joomla.

## Présences et corrections

Le tableau de présence doit distinguer participants attendus, présents, absents et états exceptionnels. Une correction ou dérogation comporte un utilisateur, une date, une raison et une trace d’audit. La borne nécessite attendance.kiosk et attendance.scan; sans `attendance.all_sessions`, son compte Joomla doit être lié à un instructeur et reste limité aux séances assignées. Une dérogation est séparée par attendance.override.

Voir [Borne QR sur Mac](borne-qr-mac.md) avant de remettre le poste au personnel.

## Rapports et réconciliation

Chaque jour ou période de clôture :

1. Comparer les paiements Square avec les commandes/paiements locaux.
2. Examiner les paiements échoués, webhooks en erreur, remboursements et commandes pending inhabituelles.
3. Vérifier les ajustements de crédits/points, les dérogations et les exports dans le journal d’audit.
4. Consulter les tâches automatiques : notifications, expirations, attente et génération de séances.
5. Archiver/partager les rapports uniquement avec les personnes autorisées et selon la politique de conservation.

Un rapport affiche des données réelles filtrées par ACL; il ne doit pas être utilisé comme mécanisme de modification de données.

## Contrôles périodiques

- Chaque semaine : vérifier sauvegarde, échecs cron, espace disque des journaux et accès de borne.
- Chaque mois : auditer membres des groupes ACL, permissions export/Square, secrets à faire tourner et QR révoqués.
- Avant chaque mise à jour : appliquer [Installation Joomla](installation-joomla.md), [Déploiement cPanel](deploiement-cpanel.md) et la matrice de [Plan de tests](plan-tests.md).
- Après un incident : préserver les preuves, rapprocher les registres et suivre [Sécurité et exploitation](securite-exploitation.md).
