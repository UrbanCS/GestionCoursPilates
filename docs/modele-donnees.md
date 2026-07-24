# Modèle de données

Le DDL livré dans packages/com_memipilates/admin/sql/install.mysql.utf8mb4.sql est la source de vérité d’installation. Il crée 34 tables. Cette page décrit l’intention métier, les relations et les invariants à préserver lors d’une migration ou d’un audit. Tous les noms sont préfixés par #__ afin que Joomla substitue le préfixe réel de l’installation.

## Principes de stockage

- Les montants sont représentés en cents entiers ou par un type décimal sûr; aucun calcul de prix, taxe, remise ou remboursement ne doit reposer sur un flottant.
- Les registres de crédits et de points sont des écritures de mouvement. Le solde visible est une projection ou un total recalculable, jamais la seule source de vérité.
- Les références aux clients pointent vers l’utilisateur Joomla; #__memi_client_profiles étend le compte sans dupliquer son mot de passe, sa session ni son adresse e-mail comme source d’authentification.
- Les dates techniques sont stockées de façon cohérente et les dates métier sont interprétées dans le fuseau Joomla. La configuration recommandée est America/Toronto; les règles de 12 heures couvrent les changements d’heure.
- Les suppressions de cours, forfaits et journaux sont logiques ou archivées lorsque l’historique est nécessaire. Les actions comptables et de présence sont corrigées par mouvement/événement plutôt que supprimées silencieusement.
- Le DDL utilise InnoDB et déclare les clés étrangères nécessaires; les index restent indispensables pour les lectures d’exploitation et les verrous transactionnels.

## Carte relationnelle

~~~mermaid
flowchart LR
  U["#__users"] --> CP["client_profiles"]
  CT["course_types"] --> C["courses"]
  I["instructors"] --> C
  L["locations"] --> R["rooms"]
  R --> C
  C --> SR["session_rules"]
  C --> S["sessions"]
  SR --> S
  CP --> B["bookings"]
  S --> B
  S --> W["waitlist"]
  CP --> W
  P["packages"] --> PCT["package_course_types"]
  CT --> PCT
  P --> CUP["customer_packages"]
  CP --> CUP
  CUP --> CL["credit_ledger"]
  B --> CL
  O["orders"] --> OI["order_items"]
  O --> PAY["payments"]
  PAY --> RF["refunds"]
  OI --> P
  CP --> QR["qr_tokens"]
  B --> A["attendance"]
  QR --> A
  A --> PL["points_ledger"]
  CP --> PL
  RW["rewards"] --> RR["reward_redemptions"]
  CP --> RR
~~~

## Tables fonctionnelles

| Domaine | Tables | Rôle |
| --- | --- | --- |
| Catalogue | #__memi_course_types, #__memi_instructors, #__memi_locations, #__memi_rooms, #__memi_courses | Définit les cours, personnes, salles, capacités, prix, crédits requis, statut de publication et informations visibles. |
| Horaire | #__memi_session_rules, #__memi_sessions | Conserve la règle récurrente et les occurrences concrètes. Une génération relancée doit retrouver l’occurrence existante au lieu d’en créer une autre. |
| Clients et réservation | #__memi_client_profiles, #__memi_bookings, #__memi_waitlist | Lie le compte Joomla au profil studio, au statut de réservation et à l’ordre de la liste d’attente. |
| Forfaits et crédits | #__memi_packages, #__memi_package_course_types, #__memi_customer_packages, #__memi_credit_ledger | Décrit l’offre vendable, ses restrictions de types de cours, son attribution au client et tous les mouvements de crédits : achat, utilisation, restauration, expiration, ajustement et correction. |
| Promotion | #__memi_promotions, #__memi_promotion_course_types, #__memi_promotion_packages, #__memi_promotion_redemptions | Porte les limites globales/par client, les restrictions de cours/forfaits et la preuve de chaque utilisation. |
| Commande et Square | #__memi_orders, #__memi_order_items, #__memi_payments, #__memi_refunds, #__memi_square_webhooks | Lie les intentions et montants locaux aux identifiants Square, états de paiement, remboursements et événements signés. |
| QR et présence | #__memi_qr_tokens, #__memi_attendance, #__memi_scan_attempts, #__memi_rate_limits | Conserve l’empreinte du QR, la présence finale, les diagnostics de scan minimisés et les protections contre les tentatives excessives. |
| Fidélité | #__memi_points_ledger, #__memi_rewards, #__memi_reward_redemptions | Gère les gains, usages, expirations et échanges de points de manière traçable. |
| Communication et configuration | #__memi_notifications, #__memi_email_templates, #__memi_settings | Suit les notifications dédoublonnées, les modèles et les paramètres applicatifs. Les paramètres marqués is_secret ne sont jamais exportés ni journalisés. |
| Traçabilité | #__memi_audit_log | Enregistre les actions sensibles avec acteur, objet, ancienne/nouvelle valeur minimisées, justification et contexte licite. |

## Invariants et index critiques

Les migrations doivent préserver au minimum les propriétés suivantes :

| Invariant | Contrôle attendu |
| --- | --- |
| Une occurrence récurrente n’existe qu’une fois | Clé unique dérivée de la règle/cours et de son début, plus génération transactionnelle. |
| Un client ne bloque pas deux places actives sur la même séance | La valeur dérivée active_booking_key est unique lorsqu’elle est non nulle; elle est libérée seulement à la transition terminale autorisée. |
| La dernière place ne peut pas être vendue deux fois | Vérification de capacité sous transaction et verrouillage/condition atomique sur la séance. |
| Une utilisation promo est comptée une fois | Référence de redemption unique et compteur mis à jour atomiquement. |
| Un paiement Square n’est rapproché qu’une fois | Identifiant Square unique, clé d’idempotence locale et statut de commande contrôlé côté serveur. |
| Un webhook répété n’a aucun second effet | square_event_id unique, signature validée avant traitement, résultat rejouable. |
| Un QR ne révèle pas l’identité et un seul reste actif | Jeton opaque signé et versionné, empreinte à sens unique au lieu du jeton brut, active_token_key unique lorsqu'elle est non nulle et régénération protégée par idempotency_key. |
| Une présence n’est créée qu’une fois | Unicité de la paire session_id/client_id et clé d’idempotence; présence et points dans une seule transaction. |
| Les points ne sont pas doublés | Clé d’idempotence unique par événement déclencheur (scan, paiement, promotion ou ajustement). |
| Les limites de scan résistent à plusieurs requêtes | État de limite et tentative mis à jour de façon atomique, sans journaliser le QR complet. |

Les index doivent couvrir les lectures d’exploitation : séances par début/statut/salle, réservations par séance/client/statut, liste d’attente par séance/position/statut, expirations par date/statut, paiements par identifiant externe/statut, QR par empreinte/statut, présences par séance/client et journaux par entité/date.

## États métier

Les états de réservation autorisés sont :

| État | Signification |
| --- | --- |
| pending | Intention créée, mais paiement/validation non terminé. |
| payment_pending | Place retenue temporairement pour un paiement direct non terminé. |
| payment_failed | Paiement définitivement échoué; la place a été libérée. |
| payment_expired | Commande de séance abandonnée; la retenue a expiré et la place a été libérée. |
| confirmed | Place confirmée et capacité réellement consommée. |
| waitlisted | Client dans la file, sans consommation initiale de crédit. |
| cancelled_on_time | Annulation avant le délai configuré; le registre restaure le crédit si applicable. |
| cancelled_late | Annulation trop tardive; aucune restauration automatique. |
| attended | Présence confirmée. |
| no_show | Absence identifiée selon la règle planifiée. |
| refunded | État lié à un traitement de remboursement explicite. |
| administratively_cancelled | Annulation par le studio ou l’administration, avec trace et restauration selon règle. |

Les statuts supplémentaires de paiement, commande, offre d’attente, QR et notification sont définis dans le DDL et les services. Une transition doit être validée côté serveur; un formulaire ne peut pas choisir arbitrairement un état terminal.

## Registres et idempotence

Chaque mouvement de #__memi_credit_ledger et #__memi_points_ledger doit identifier l’événement source, l’utilisateur concerné, le montant signé, la date, l’acteur administratif éventuel et une clé d’idempotence. Les corrections sont de nouveaux mouvements avec justification, jamais un UPDATE d’un mouvement précédent.

Les tables #__memi_payments et #__memi_square_webhooks relient :

- l’ID de commande et d’item local;
- l’ID de paiement/remboursement/square_event_id Square;
- l’environnement Square;
- la clé d’idempotence et l’heure de traitement;
- un statut contrôlé et une erreur nettoyée si nécessaire.

Les QR, signatures de webhook, tokens de paiement et mots de passe ne font pas partie du modèle historique : seules les références minimales, empreintes ou identifiants externes nécessaires à l’audit sont conservés. La table de webhooks peut conserver le payload reçu pour reprise technique; il doit être traité comme une donnée opérationnelle sensible, protégé, soumis à rétention et jamais recopié dans les journaux de débogage.

## Cycle de vie et confidentialité

Un export client, réservation, paiement, présence ou points est soumis à l’action ACL export.data et à l’objectif opérationnel. Exporter seulement les colonnes nécessaires, consigner l’export et appliquer la rétention approuvée. Les journaux d’audit ne doivent pas contenir un token QR, un numéro de carte, un CVV, un access token Square, un mot de passe ni une signature de webhook.

Avant une purge ou une migration destructive, prendre une sauvegarde, tester une restauration et vérifier les obligations de conservation. Une anonymisation doit laisser les totaux comptables, les clés d’idempotence et la cohérence des registres exploitables sans réidentifier la personne.
