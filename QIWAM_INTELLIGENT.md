# Qiwam assistant — POC IA & Vocal

Ce POC ajoute une couche IA conversationnelle (texte + vocal) au-dessus de Qiwam ERP,
en s'appuyant sur l'**Inference API gratuite de Hugging Face**.

## Architecture

```
Browser ─► /api/v1/ai/voice ─► Whisper (HF) ─► texte
                                          │
                                          ▼
                              Llama 3.3 70B (HF)  ◄── tools schema
                                          │
                              ┌───────────┴───────────┐
                              │                       │
                       AddStockMovementTool    QueryStockTool
                              │
                              ▼
                       Eloquent (Product)
```

## Setup

1. Créer un token Hugging Face gratuit → https://huggingface.co/settings/tokens
2. Copier dans `.env` :
   ```env
   HF_TOKEN=hf_xxxxxxxxxxxxxxxxxxxx
   AI_CHAT_MODEL=meta-llama/Llama-3.3-70B-Instruct
   AI_WHISPER_MODEL=openai/whisper-large-v3-turbo
   ```
3. C'est tout. Aucune migration nécessaire pour le POC.

## Endpoints

| Méthode | Route | Auth | Description |
|---------|-------|------|-------------|
| GET  | `/api/v1/ai/tools` | Bearer | Liste les outils exposés au LLM |
| POST | `/api/v1/ai/text`  | Bearer | Envoie une commande texte |
| POST | `/api/v1/ai/voice` | Bearer | Envoie un fichier audio (multipart `audio`) |

### Exemple `POST /api/v1/ai/text`

```json
{ "message": "Ajoute 50 unités de Tissu Bazin au stock" }
```

Réponse :
```json
{
  "transcript": "Ajoute 50 unités de Tissu Bazin au stock",
  "action": "add_stock_movement",
  "tool_result": {
    "ok": true,
    "product_name": "Tissu Bazin Premium",
    "previous_stock": 142,
    "new_stock": 192,
    "movement": 50,
    "message": "+50 Tissu Bazin Premium ajoutés. Nouveau stock : 192."
  },
  "reply": "C'est fait : +50 Tissu Bazin Premium. Stock actuel : 192."
}
```

## Ajouter un nouveau tool

1. Implémenter `App\Services\Ai\Tools\AiTool`
2. Le déclarer dans `App\Services\Ai\ToolRegistry::__construct()`

C'est tout. Le LLM le voit automatiquement à la prochaine requête.

## Idées de tools à venir

- `create_order` — créer une commande client par la voix
- `query_dashboard` — répondre en langage naturel aux KPIs
- `start_production_run` — lancer un OF depuis une recette
- `add_customer` — créer un client
- `record_expense` — enregistrer une dépense
- `analyze_sales_trend` — répondre à "mon top vente du mois ?"

## Limites connues du free tier

- Rate limits HF : ~300 req/h sur le plan gratuit
- Latence : 1-3s par tour LLM, 2-4s pour Whisper
- Pas de SLA → garder un fallback rule-based pour les actions critiques (caisse)
