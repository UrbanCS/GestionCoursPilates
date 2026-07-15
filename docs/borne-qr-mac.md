# Borne QR sur Mac

La borne est une vue Joomla intégrée, pas une application macOS distincte. Sa route technique est :

~~~text
index.php?option=com_memipilates&view=kiosk
~~~

Une URL de menu Joomla peut être utilisée en production. La borne reste disponible dans Safari, Chrome et Firefox modernes, sur HTTPS, avec un compte Joomla d’employé limité.

## Préparation du poste

1. Réserver un compte macOS dédié au studio, non administrateur, avec verrouillage automatique de l’écran.
2. Utiliser un compte Joomla individuel ou un compte de borne approuvé, membre uniquement du groupe doté de attendance.kiosk et attendance.scan. Ne jamais utiliser un Super administrateur sur le poste partagé.
3. Ouvrir la borne, sélectionner le cours actif, puis choisir le mode **Lecteur QR externe**, **Caméra** ou **Recherche manuelle**.
4. Garder la page ouverte en plein écran au besoin. Le plein écran est une préférence d’ergonomie : il ne remplace pas la session Joomla, l’ACL ou le verrouillage du Mac.
5. Vérifier chaque jour l’heure du Mac, la connexion réseau HTTPS et le cours sélectionné avant l’arrivée des clients.

La vue expose une racine [data-memi-kiosk]. Son script garde le focus sur le champ de saisie, vide le champ après traitement et bloque les scans simultanés.

## Choisir et connecter un lecteur QR HID

Choisir un lecteur USB ou Bluetooth compatible **HID keyboard / keyboard wedge**. Il doit envoyer le contenu du QR comme si un clavier le tapait, puis envoyer une touche Entrée. Aucun pilote ou logiciel macOS propriétaire ne doit être requis pour la borne.

### Lecteur USB

1. Brancher le lecteur directement au Mac ou à un concentrateur alimenté.
2. Attendre sa reconnaissance comme clavier; un lecteur HID ne doit pas demander d’application.
3. Ouvrir la borne et passer au **Mode de test du lecteur**.
4. Scanner un QR de test non réel. Vérifier la réception des caractères, la touche Entrée, la longueur, la durée, le format et l’état du focus.
5. Confirmer que le test n’a créé aucune présence ni point, puis revenir au mode Lecteur QR externe.

### Lecteur Bluetooth

1. Charger le lecteur, le mettre en mode jumelage et ouvrir **Réglages Système → Bluetooth** sur le Mac.
2. Associer le lecteur comme clavier. Si macOS demande une disposition de clavier, suivre la procédure du fabricant sans installer d’outil permanent inutile.
3. Répéter le test de lecture sur la borne. Vérifier la reconnexion après veille et préparer un câble USB de secours.

### Réglages du lecteur

Suivre la documentation du fabricant pour régler :

- le mode HID clavier;
- aucun préfixe non nécessaire;
- un suffixe Entrée/CR unique;
- une disposition compatible avec les caractères autorisés dans les jetons opaques;
- un délai d’inter-caractères qui ne tronque pas le scan;
- une symbologie QR activée.

Ne scanner pas un QR client dans un éditeur de texte, une barre d’adresse, un ticket ou un chat pour diagnostiquer le matériel. Utiliser exclusivement le Mode de test de la borne, qui ne garde pas le token complet.

## Flux quotidien de présence

1. Se connecter à Joomla et ouvrir la borne.
2. Vérifier le titre, l’instructeur, la salle et l’heure du cours sélectionné. Sélectionner manuellement un cours si aucun cours actif n’est proposé.
3. Vérifier que le champ est en état « prêt » et que le mode Lecteur QR externe est sélectionné.
4. Inviter le client à présenter son QR. Le lecteur envoie le token puis Entrée.
5. La page effectue un POST vers la tâche kiosk.scan avec le token, session_id, la méthode hid ou camera, une idempotency_key et un jeton CSRF Joomla si fourni.
6. Le serveur vérifie le QR, la réservation, l’état du cours, les autorisations et l’absence de présence antérieure; il crée la présence et les points dans une transaction.
7. Lire le résultat affiché, puis attendre le retour automatique au mode prêt avant le client suivant.

Un succès affiche seulement les informations utiles au comptoir : client, cours, présence confirmée et points lorsque la politique le permet. Un deuxième scan doit signaler une présence déjà enregistrée sans créer de second mouvement.

## Mode test du lecteur

Le mode test sert uniquement à diagnostiquer l’entrée HID. Il affiche :

- réception des caractères;
- détection de la touche Entrée;
- longueur et conformité de format;
- durée totale de lecture;
- état du focus;
- indication USB/Bluetooth si le navigateur peut réellement la déterminer.

Il est entièrement local : aucune requête n’est envoyée, aucune présence/point/réservation n’est modifié et le QR complet n’est ni affiché, ni enregistré, ni journalisé. L’accès au bouton est gouverné par kiosk.test.

La vue charge les assets Joomla com_memipilates.kiosk et com_memipilates.kiosk-scanner. Elle utilise BarcodeDetector lorsqu’il est disponible, puis la bibliothèque MIT qr-scanner 1.4.2 et son worker livrés localement sous media/vendor. Le scan caméra n’a donc aucune dépendance réseau/CDN à l’exécution. Pour les contrôles automatisés et l’accessibilité, le panneau de test expose uniquement les métriques suivantes :

~~~text
[data-memi-kiosk-test-field="received|chars|length|enter|duration|format|focus|transport"]
~~~

Ces champs ne contiennent jamais la valeur du QR.

## Caméra comme solution de secours

1. Confirmer que la page est servie en HTTPS. getUserMedia ne doit pas être proposé comme solution de production sur HTTP.
2. Choisir **Caméra** dans la borne et autoriser la caméra dans le navigateur lorsque le Mac le demande.
3. Sur mobile/tablette, préférer la caméra arrière lorsqu’elle est disponible. Sur Mac, utiliser la caméra intégrée ou la caméra externe autorisée.
4. Présenter le QR à la caméra; le même traitement serveur que le lecteur HID est utilisé.
5. Arrêter la caméra après le cours ou si elle n’est pas nécessaire. En cas de refus de permission, utiliser Recherche manuelle.

Le lecteur de QR côté navigateur aide à extraire le token, mais n’est jamais une autorité métier. Tous les contrôles, la présence et les points restent côté serveur.

## Recherche manuelle et exceptions

La Recherche manuelle est réservée à un employé ayant attendance.manual. Elle recherche un client dans le contexte du cours actif, sélectionne la réservation pertinente puis soumet une action de présence contrôlée. Les hooks frontend memi:kiosk-manual-search et memi:kiosk-manual-select servent à l’interface; l’endpoint recommandé kiosk.search doit minimiser les résultats et appliquer l’ACL.

Si le client n’a pas de réservation, est sur liste d’attente, se présente trop tôt/tard ou a une réservation annulée, ne promettre aucune entrée au client. La borne doit demander une intervention. Seul un employé doté de attendance.override peut déroger à une règle; il doit fournir une raison, et le serveur journalise l’acteur, le contexte et le résultat.

Pour corriger une présence erronée, utiliser l’interface prévue et attendance.undo. Ne jamais supprimer une ligne SQL ni réinitialiser les points à la main; une correction est auditable et peut nécessiter un mouvement de points compensatoire.

## QR client : révocation et régénération

Un client peut afficher ou imprimer son QR depuis son espace client. Le token est opaque et ne contient ni nom ni courriel. En cas de perte, soupçon de copie ou changement de support :

1. Ouvrir le profil client avec qr.manage.
2. Révoquer le QR courant.
3. Générer un nouveau QR et remettre seulement le nouveau support au client.
4. Vérifier qu’un ancien QR est refusé et que le nouveau est accepté.
5. Consulter le journal d’audit; ne copier aucun token dans une note.

## Résolution de problèmes

| Symptôme | Vérification et correction |
| --- | --- |
| Rien ne se passe au scan | Revenir au mode Lecteur, cliquer l’état prêt, vérifier le focus, puis utiliser le Mode test. Vérifier USB/Bluetooth et le suffixe Entrée. |
| Code tronqué ou invalide | Contrôler la disposition de clavier, la symbologie QR, le délai du lecteur et les caractères autorisés. Ne pas augmenter la longueur maximale sans revue sécurité. |
| Double résultat | Attendre la fin du message et vérifier que le lecteur n’envoie qu’une entrée. L’idempotence serveur doit refuser la deuxième présence; signaler tout écart. |
| Caméra absente/refusée | Vérifier HTTPS, les permissions du navigateur/macOS, fermer les applications qui utilisent la caméra, puis proposer la recherche manuelle. |
| Mauvais cours sélectionné | Arrêter les scans, sélectionner le bon cours et informer l’employé. Corriger les présences avec attendance.undo si nécessaire. |
| Le résultat indique absence de réservation | Vérifier le cours et le profil; ne pas utiliser une dérogation sans permission et justification. |
| La borne ne garde pas le focus | Recharger une fois, fermer les extensions qui capturent le clavier, éviter de laisser un champ externe actif et utiliser le bouton de retour au scan. |

## Fin de journée

Vérifier le nombre attendu de participants, les erreurs et dérogations, puis fermer la session Joomla ou verrouiller le Mac. Ne pas laisser un navigateur authentifié et déverrouillé dans l’accueil. Consigner les incidents, QR révoqués et corrections de présence selon la politique du studio.
