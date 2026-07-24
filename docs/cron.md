# Tâches planifiées et cron cPanel

Les tâches Memi Pilates doivent être idempotentes. Une seconde exécution ne peut pas créer une seconde séance, offre de liste d’attente, notification, présence, attribution de points ou expiration.

## Commande cPanel livrée

Le runner CLI autonome livré par le paquet est la voie recommandée sur cPanel :

~~~sh
[PHP_BIN] [JOOMLA_ROOT]/cli/memipilates.php --horizon-days=60 --email-limit=100
~~~

Exemples de valeurs, à adapter sans les copier aveuglément :

~~~sh
/opt/cpanel/ea-php82/root/usr/bin/php /home/COMPTE/public_html/cli/memipilates.php --horizon-days=60 --email-limit=100
~~~

Le chemin PHP dépend du sélecteur PHP cPanel. Le découvrir avec l’aide de l’hébergeur ou depuis une session shell autorisée; il doit utiliser la même famille PHP que le site. [JOOMLA_ROOT] est le dossier contenant configuration.php et cli/joomla.php, pas nécessairement public_html.

Avant d’ajouter une tâche, confirmer que la commande existe :

~~~sh
[PHP_BIN] [JOOMLA_ROOT]/cli/memipilates.php --dry-run --horizon-days=60 --email-limit=100
~~~

Le mode dry-run ne doit ni envoyer de courriel ni modifier les registres. Les options --horizon-days et --email-limit limitent respectivement l’horizon de génération et le lot de courriels. Commencer avec des valeurs prudentes, valider les résultats sur préproduction, puis ajuster selon le volume réel.

Si une version ultérieure fournit aussi une commande de console Joomla enregistrée, l’alias facultatif peut être :

~~~sh
[PHP_BIN] [JOOMLA_ROOT]/cli/joomla.php memipilates:run
~~~

Ne pas utiliser cet alias dans cPanel tant que sa présence n’a pas été confirmée par la commande Joomla list.

## Planification recommandée

| Traitement | Fréquence cible | Garantie attendue |
| --- | --- | --- |
| Offres/expirations de liste d’attente et verrouillage des places | Toutes les 5 minutes | Une seule offre active par place; passage ordonné au suivant. |
| Retenues de paiement direct abandonnées | Toutes les 5 minutes | Libération atomique après le délai configuré, sans toucher aux paiements en rapprochement. |
| Notifications et vérification de paiements | Toutes les 5 minutes | Déduplication par clé d’événement et journal de livraison. |
| Génération de séances récurrentes | Horaire ou toutes les heures | Création idempotente, horizon configuré, sans doublon. |
| Rappels de cours | Toutes les 15 minutes | Une notification par modèle, réservation et fenêtre de rappel. |
| Expiration de forfaits, crédits, promotions et jetons | Toutes les heures | Écritures de registre auditables, relançables sans effet double. |
| Identification des absences | Toutes les heures après les cours | Ne pas écraser une présence ou une correction manuelle. |
| Nettoyage de jetons/notifications expirés | Chaque nuit | Rétention paramétrée, pas de suppression de journaux requis. |

La commande peut orchestrer l’ensemble des sous-tâches avec des verrous de base de données. Si une version offre des options de tâche ciblée, les documenter dans les notes de livraison sans dédoubler les processus parallèles.

Les notifications en échec sont reprises automatiquement avec un délai exponentiel configurable. Une réclamation d’envoi abandonnée par un processus interrompu redevient admissible après 15 minutes. À la dernière tentative, une offre de liste d’attente est annulée, sa place temporaire est libérée et le candidat suivant est promu; les autres notifications restent en état d’échec pour inspection.

Chaque exécution expire d’abord les commandes de séance encore `pending` au-delà du délai de retenue, puis permet à la liste d’attente d’utiliser la place libérée. Elle rapproche également les commandes `payment_processing` âgées d’au moins deux minutes et les remboursements `processing`/`pending`. Un paiement est identifié par une référence immuable propre à la tentative (`memi-o-{commande}-{empreinte}`), son montant, sa devise et son emplacement; si Square n’a aucun paiement finalisé, l’essai est annulé par sa clé d’idempotence avant d’autoriser une nouvelle carte. Un remboursement sans réponse est rejoué avec exactement la même clé et les mêmes paramètres. Les remboursements en attente utilisent un recul progressif (5 minutes, 30 minutes, 6 heures, puis 24 heures); après 14 jours, chaque nouvelle vérification produit l’audit `refund.reconcile_prolonged` afin de déclencher un suivi avec Square. Les compteurs `payment_holds_expired`, `payments_reconciled` et `refunds_reconciled` du résultat indiquent les transitions locales réalisées.

## Configuration dans cPanel

1. Ouvrir **Cron Jobs** dans cPanel avec un compte habilité.
2. Créer une tâche toutes les cinq minutes ou à la fréquence définie par l’opération. Commencer avec l’orchestration centrale, pas avec plusieurs tâches qui touchent les mêmes listes.
3. Fournir la commande avec chemins absolus :

~~~sh
*/5 * * * * [PHP_BIN] [JOOMLA_ROOT]/cli/memipilates.php --horizon-days=60 --email-limit=100 >> [PRIVATE_LOG_DIR]/memipilates-cron.log 2>&1
~~~

4. Placer [PRIVATE_LOG_DIR] hors de la racine Web, avec une rotation adaptée. Ne jamais rediriger les logs dans un dossier public.
5. Ajouter une adresse d’alerte cPanel ou un contrôle externe autorisé pour les échecs; limiter les notifications de réussite pour éviter le bruit.
6. Lancer une fois la commande manuellement et vérifier les résultats métier, pas seulement le code de sortie.

Si l’hébergeur fournit flock, il peut empêcher un chevauchement au niveau shell, mais l’application doit conserver son propre verrou transactionnel : un appel manuel, une tâche Joomla ou deux serveurs ne doivent pas créer de doublon.

## Alternative : Tâches planifiées Joomla

Si le site dispose du gestionnaire de tâches Joomla et qu’il est déjà supervisé, il peut déclencher la même logique. Préférer la CLI cPanel lorsqu’elle est disponible : elle ne nécessite pas d’exposer un déclencheur HTTP. Toute tâche HTTP doit avoir une authentification forte, une limitation de débit et ne contenir aucun secret dans l’URL.

## Procédure de test

1. Sur préproduction, préparer une séance récurrente, une offre de liste d’attente proche de l’expiration, un forfait proche de l’expiration et un rappel éligible.
2. Exécuter la commande une fois; vérifier les lignes de registre, courriels de test et journaux.
3. Exécuter immédiatement la même commande une seconde fois; vérifier l’absence de doublon.
4. Simuler une interruption après la réservation temporaire d’une place puis relancer; vérifier la reprise ordonnée.
5. Vérifier que le journal ne contient ni QR complet, ni e-mail inutile, ni access token, ni donnée carte.
6. Installer la tâche cPanel, attendre une exécution réelle, puis contrôler l’heure, le code de sortie et les effets métier.

En cas d’échec persistant, désactiver les traitements automatiques à risque (notamment promotion/paiement) et appliquer la procédure de reprise. Ne pas effacer le verrou ou le journal sans déterminer si une transaction est encore active.
