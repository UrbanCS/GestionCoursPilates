# Square : Sandbox, Production et webhooks

## Architecture de paiement

Le navigateur utilise le Web Payments SDK pour présenter les champs de carte et obtenir un jeton de paiement à usage unique. La tokenisation reçoit le montant, la devise, l’intention `CHARGE`, le contexte d’initiative client et les coordonnées déjà connues du compte afin que Square puisse appliquer la vérification acheteur/3-D Secure. Le navigateur n’envoie le jeton obtenu qu’au contrôleur Joomla protégé. Le serveur crée ou met à jour le paiement Square, rapproche l’ordre local et ne confirme la réservation qu’après une réponse serveur valide et/ou le traitement idempotent de l’événement webhook pertinent.

Deux parcours utilisent ce même traitement : l’achat d’un forfait crédite le compte après paiement, tandis que le paiement direct d’une séance retient atomiquement une place puis confirme automatiquement la réservation après le statut Square `COMPLETED`. Une retenue abandonnée expire selon le délai configuré et libère la capacité; une tentative dont la réponse réseau est incertaine reste protégée jusqu’au rapprochement Square.

Le navigateur peut recevoir l’identifiant d’application et d’emplacement publics. Il ne doit jamais recevoir le jeton d’accès Square, la clé de signature de webhook, le secret de configuration ou des données carte. Les numéros complets de carte et CVV ne passent jamais par Joomla ni ses journaux.

Le SDK Web Payments est fourni par Square et doit être chargé conformément à sa documentation et à la politique CSP actuelle de Square; tous les autres médias du composant, dont le lecteur QR, restent distribués localement. Square exige un contexte sécurisé et une CSP correcte pour le Web Payments SDK. Voir la documentation officielle : [Web Payments SDK](https://developer.squareup.com/docs/web-payments/overview), [Sandbox](https://developer.squareup.com/docs/devtools/sandbox/overview) et [webhooks de paiements](https://developer.squareup.com/docs/payments-api/webhooks).

## Secrets et paramètres

| Paramètre | Visibilité | Règle |
| --- | --- | --- |
| Application ID | Public côté navigateur si nécessaire | Différent entre Sandbox et Production; ne suffit pas à encaisser. |
| Location ID | Public côté navigateur si nécessaire | Doit appartenir au même environnement que l’application. |
| Access token | Serveur uniquement | Secret; jamais dans JavaScript, Git, URL, réponse API ou journal. |
| Webhook signature key | Serveur uniquement | Secret distinct; vérifier la signature avant de lire l’événement métier. |
| Environnement | Administrateur restreint | Valeur explicite sandbox ou production, jamais déduite d’une URL. |

Configurer les secrets dans un emplacement serveur protégé et non versionné, ou dans des champs Joomla protégés accessibles uniquement aux Super administrateurs. Exclure ces valeurs des exports de configuration, diagnostics et captures d’écran. Une rotation de clé doit être possible sans changer le code.

Lorsque l’hébergement permet de fournir des variables d’environnement au processus PHP, le service lit en priorité MEMI_SQUARE_ACCESS_TOKEN et MEMI_SQUARE_WEBHOOK_SIGNATURE_KEY. Elles constituent une solution préférée aux valeurs persistées dans la configuration Joomla. Ne pas tenter de les définir dans un script cron, une URL ou un fichier sous la racine Web : utiliser le mécanisme secret approuvé par l’hébergeur.

## Configuration Sandbox

1. Créer/ouvrir l’application dans le tableau de bord développeur Square et basculer explicitement sur le Sandbox.
2. Créer ou sélectionner un emplacement Sandbox. Relever uniquement les identifiants de test nécessaires dans le gestionnaire de secrets de préproduction.
3. Dans les options de com_memipilates, choisir **Sandbox**, enregistrer l’Application ID, le Location ID, l’access token et la clé de signature Sandbox.
4. Ouvrir l’URL HTTPS de préproduction et vérifier que le formulaire Square se charge avec l’environnement Sandbox.
5. Déclarer l’URL webhook affichée/configurée par le composant dans le tableau Square Sandbox. Elle doit correspondre exactement à l’URL publique réellement reçue par Joomla, y compris son schéma HTTPS et son chemin. Ne pas la reconstruire à partir d’une URL SEF devinée.
6. S’abonner au minimum aux événements de création/mise à jour de paiement et aux événements de remboursement utilisés par la version livrée. Enregistrer l’ID Square d’événement dans le registre webhook avant de produire un effet métier.
7. Réaliser les scénarios de réussite, refus, webhooks répétés, remboursement administratif et reprise après délai. Les valeurs de test actuelles sont maintenues par Square dans [Sandbox Payments](https://developer.squareup.com/docs/devtools/sandbox/payments).

Un paiement Sandbox ne débite pas de carte réelle. Il reste néanmoins soumis aux mêmes règles locales : montant en cents, idempotency key, commande locale, statut et absence de réservation confirmée en cas d’échec.

## Passage à Production

Le passage est une opération contrôlée, distincte de la publication du code :

1. Tous les tests Sandbox critiques sont réussis et consignés.
2. La politique de confidentialité, les reçus, les taxes et l’identité du studio sont validés.
3. L’URL de production est HTTPS, sans authentification de préproduction ni redirection cassée.
4. Créer/configurer la souscription webhook **Production** avec l’URL de production exacte et saisir la clé de signature Production dans le magasin de secrets.
5. Renseigner les identifiants Production dans l’administration restreinte, puis effectuer un test contrôlé selon les procédures Square du studio.
6. Vérifier le rapprochement entre l’ID de paiement Square, la commande, le client, le forfait et la réservation locaux.
7. Réserver un créneau d’observation : surveiller les journaux masqués, les échecs webhook et la concordance des montants durant les premières transactions.

Ne mélangez jamais une Application ID/Location ID Sandbox avec un access token Production. Un changement d’environnement doit invalider les tentatives de paiement en cours plutôt que les faire basculer silencieusement.

## Contrat de traitement

- Une commande locale débute dans un état non payé; une réservation payante reste `payment_pending` jusqu’à confirmation serveur.
- Chaque demande de création de paiement possède une clé d’idempotence unique, persistée et liée à une seule intention de commande.
- L’ID de paiement Square est unique localement. Un même événement webhook possède également un identifiant unique local.
- Un webhook est d’abord authentifié par signature, puis validé pour le bon environnement et le bon emplacement. Les contrôles ne doivent pas être contournés par un statut envoyé par navigateur.
- Les doublons de callback ou de webhook réussissent sans réattribuer forfait, crédit, réservation ou points.
- Un remboursement est une action administrative explicite et auditée. Il ne déclenche pas automatiquement une restauration de crédit sans règle métier explicite.
- L’annulation d’une réservation payée directement ne rembourse pas automatiquement la carte; le remboursement Square demeure une action administrative explicite.

## Diagnostic sûr

Pour une anomalie, conserver : ID de commande local, ID de paiement Square, ID d’événement webhook, horodatage, statut et code d’erreur nettoyé. Ne conserver ni token de carte, ni signature brute, ni access token, ni données de carte. Utiliser les journaux webhook du tableau Square pour comparer les tentatives, puis rejouer seulement un traitement idempotent validé.
