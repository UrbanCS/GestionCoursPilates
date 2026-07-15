# Sauvegarde, reprise et retour arrière

## Objectif

Une sauvegarde utile est restaurable, chiffrée lorsque son emplacement l’exige et testée. Pour une livraison Memi Pilates, conserver trois ensembles cohérents :

1. les fichiers Joomla et le ZIP exact de la version installée;
2. la base de données complète, incluant les tables Joomla et #__memi_*;
3. les paramètres opérationnels et preuves de configuration, sans recopier les secrets en clair.

Les données de paiement, crédit, points et présence sont des registres. Ne jamais les modifier directement pour « faire correspondre » une ancienne sauvegarde.

## Avant une migration ou une mise en production

1. Noter la version Joomla, PHP, la version du paquet, le hash SHA-256 du ZIP, l’heure et l’opérateur.
2. Mettre en pause les opérations à risque : encaissement, confirmation de réservation, scan de présence et promotion automatique de liste d’attente.
3. Réaliser une sauvegarde cPanel des fichiers de la racine Joomla, en excluant au besoin les caches régénérables mais jamais configuration.php ou les médias nécessaires.
4. Exporter la base dans phpMyAdmin ou utiliser le mécanisme de sauvegarde autorisé par l’hébergeur. La sauvegarde doit inclure structure, données, triggers/contraintes lorsqu’ils existent et le bon jeu de caractères.
5. Conserver le fichier de sauvegarde hors du répertoire web, avec accès limité. Ne jamais l’envoyer par courriel non chiffré ou dans le dépôt.
6. Enregistrer l’emplacement, la taille et la somme de contrôle, puis tester périodiquement une restauration sur préproduction.

Sur un accès SSH explicitement autorisé, un export MySQL cohérent peut utiliser un compte de sauvegarde restreint et une configuration de client protégée. Ne pas mettre le mot de passe dans la ligne de commande, l’historique shell ou un fichier du dépôt.

## Rétention recommandée

Adapter la durée aux obligations légales et comptables du studio. À défaut :

- une sauvegarde avant chaque déploiement;
- des sauvegardes quotidiennes conservées au moins 30 jours;
- des sauvegardes mensuelles conservées selon la politique comptable;
- un exercice de restauration documenté au moins trimestriellement.

Les exports contenant noms, courriels, présences ou reçus sont des données personnelles : limiter leur accès et les supprimer conformément à la politique de rétention approuvée.

## Arbre de décision de retour arrière

| Situation | Réponse sûre |
| --- | --- |
| L’installation échoue avant toute migration | Conserver le message d’erreur, ne pas réinstaller en boucle; corriger en préproduction ou restaurer les fichiers touchés. |
| La migration échoue et aucune opération métier n’a eu lieu | Désactiver l’extension, restaurer le snapshot de base et le paquet précédent après vérification. |
| Le code fonctionne mal mais les nouvelles tables/données sont compatibles | Désactiver les fonctions risquées et installer le paquet précédent avec une migration explicitement compatible. |
| Un paiement ou une réservation a eu lieu après migration | Geler les opérations, rapprocher les transactions Square et utiliser des écritures compensatoires. Ne pas restaurer aveuglément la base. |
| Compromission ou fuite suspectée | Révoquer les secrets/tokens, préserver les preuves, isoler le service et suivre le plan d’incident de sécurité. |

## Procédure contrôlée

1. **Stabiliser.** Informer les opérateurs, désactiver temporairement les routes de paiement et la borne, puis empêcher les cron concurrents.
2. **Préserver les preuves.** Archiver les journaux applicatifs, les IDs Square, les erreurs et la liste des opérations depuis le dernier backup. Ne pas y ajouter de secrets.
3. **Décider la méthode.** Préférer un paquet précédent compatible et une migration de reprise. N’employer une restauration complète de la base que si elle ne fait perdre aucune opération métier non rapprochée.
4. **Restaurer dans un environnement isolé d’abord.** Restaurer fichiers et base dans une préproduction, contrôler les URL, tables, révisions et données attendues.
5. **Restaurer production.** Avec une fenêtre d’arrêt confirmée, restaurer le snapshot cohérent via les outils cPanel/phpMyAdmin prévus, remettre les fichiers de la même révision, vider les caches ciblés et vérifier les permissions.
6. **Réconcilier.** Comparer Square, réservations, crédits, points, présences et notifications depuis le point de sauvegarde. Créer des mouvements compensatoires traçables si nécessaire.
7. **Valider puis rouvrir.** Réaliser les contrôles de fumée, relancer le cron une fois et réactiver les fonctions dans cet ordre : lecture de l’horaire, réservations, paiement, borne.

Une désinstallation Joomla ne remplace pas une restauration : elle peut supprimer des enregistrements d’extension tout en laissant ou en retirant des données selon la version. Ne jamais l’utiliser comme un bouton « retour arrière ».

## Liste de vérification après reprise

- Le frontend Joomla, les pages existantes et l’administration sont accessibles.
- Les tables #__memi_* et leurs contraintes correspondent à la version remise en service.
- Les groupes ACL sont conservés et l’employé de borne reste au moindre privilège.
- Les réglages Square pointent vers l’environnement voulu et le webhook signé est toujours valide.
- Aucun paiement Square ne reste sans ordre/réservation local à rapprocher.
- Le dernier scan, crédit et mouvement de points connu n’est ni dupliqué ni perdu.
- Les tâches cron n’exécutent pas en double les promotions, rappels ou expirations.
- L’incident, les actions, le résultat et les éventuels mouvements compensatoires sont consignés dans le journal d’audit.
