# Audit de Compatibilité - Warmup Pro V3.4 (Phase 1)

## Résumé
Audit réalisé pour vérifier la conformité avec l'API Postal réelle et préparer l'implémentation du système de threads conversationnels.

## A. Incompatibilités Événements Postal
| Fichier | Ligne (approx) | Problème | Correction | Sévérité |
| :--- | :--- | :--- | :--- | :--- |
| `src/API/WebhookHandler.php` | 94 | Utilisation de `MessageDelivered` | Remplacer par `MessageSent` (Postal n'envoie pas Delivered) | **Bloquant** |
| `src/API/WebhookHandler.php` | 100 | Gestion de `MessageLoaded` | Événement valide, mais rare. Vérifier si activé dans Postal. | Faible |
| `src/API/WebhookHandler.php` | 103 | `DomainDNSError` | Correct, critique pour la délivrabilité. | Info |

## B. Mauvais Accès au Payload
| Fichier | Ligne (approx) | Problème | Correction | Sévérité |
| :--- | :--- | :--- | :--- | :--- |
| `src/API/WebhookHandler.php` | Multiple | `$payload['message']['headers']['In-Reply-To']` potentiel | Utiliser `$payload['in_reply_to']` directement (racine du payload webhook) | **Moyen** |
| `src/API/WebhookHandler.php` | 134 | Accès à `original_message` dans `track_metric` | Vérifier si Postal envoie `original_message` dans tous les events. Souvent c'est juste `message`. | Moyen |

## C. Dépendances & Signatures
| Fichier | Méthode | Problème | Correction | Sévérité |
| :--- | :--- | :--- | :--- | :--- |
| `src/Services/QueueManager.php` | `add()` | Signature OK, mais `meta` doit inclure `thread_id` pour le futur | Prévoir extension du tableau `$meta` | Faible |
| `src/API/Sender.php` | `process_queue()` | Ne gère pas nativement la notion de "Reply" (In-Reply-To header) | Ajouter support pour `in_reply_to` dans `$headers` via `$meta` | **Moyen** |

## D. Cohérence Données (DB)
| Table | Colonne | Problème | Correction | Sévérité |
| :--- | :--- | :--- | :--- | :--- |
| `postal_stats_history` | `event_type` | Mélange possible `sent` / `delivered` | Standardiser sur `sent` pour l'envoi réussi. | Moyen |
| `postal_stats_history` | `message_id` | Crucial pour le threading | Vérifier que le `message_id` stocké est bien celui de Postal (avec `<...>`) et non un ID interne. | **Bloquant** |

## E. Conflits avec Threads (Futur)
| Fichier | Zone | Risque | Mitigation |
| :--- | :--- | :--- | :--- |
| `src/API/WebhookHandler.php` | `handle_incoming_message` | Logique actuelle : Auto-reply immédiat "Re: Subject" | **Conflit Majeur**. Cette logique doit être désactivée ou remplacée par `handle_human_reply` si les threads sont activés. | **Bloquant** |
| `src/Services/QueueManager.php` | `process_queue` | Délais fixes ou aléatoires simples | Le threading nécessite des délais plus longs (5-30 min) et spécifiques. S'assurer que `scheduled_at` est respecté strictement. | Moyen |

## Conclusion
Le code actuel contient une logique de "réponse automatique" basique dans `WebhookHandler` qui entrera en conflit direct avec le nouveau système de threads. Il faut impérativement conditionner ou supprimer l'ancienne logique. De plus, la confusion `MessageDelivered` vs `MessageSent` fausse les statistiques actuelles.
