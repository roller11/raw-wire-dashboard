# AI Content Analysis Setup (FREE with Ollama)

## What Is This?

An AI-powered system that automatically analyzes scraped government content and identifies the top 5-10 most:
- 📰 Newsworthy
- 🔥 Shocking/Surprising
- ⚖️ Precedent-setting
- 📊 High regulatory impact
- 🎯 Relevant to your industry

**100% FREE** - No API costs, runs locally

## Setup (5 minutes)

### Step 1: Start Ollama Container

```bash
cd D:\678-VAPE-GUY\01-RAW-WIRE_com\REPOSITORY\raw-wire-core
docker-compose up -d ollama
```

### Step 2: Download AI Model

```bash
# Download Llama 3.2 (2GB, takes 2-5 minutes)
docker exec raw-wire-core-ollama-1 ollama pull llama3.2

# Or use a smaller/faster model:
docker exec raw-wire-core-ollama-1 ollama pull phi3      # 2GB, faster
docker exec raw-wire-core-ollama-1 ollama pull mistral   # 4GB, more accurate
```

### Step 3: Test It

```bash
docker exec raw-wire-core-wordpress-1 php \
  /var/www/html/wp-content/plugins/raw-wire-dashboard/scripts/test-ai-analyzer.php
```

That's it! ✅

## How It Works

```
Scraped Content (100+ items)
    ↓
Quick Filter (keyword check)
    ↓ (30-40 items)
AI Analysis (scores each item)
    ↓
Top 10 Findings (sorted by score)
    ↓
Dashboard Display
```

### Scoring Criteria

Each item scored 1-10 on:
- **Newsworthy** (20% weight): Industry impact
- **Shocking** (15% weight): Surprise factor
- **Precedent-setting** (25% weight): First-of-its-kind
- **Regulatory Impact** (20% weight): Business effect
- **Uniqueness** (10% weight): Rarity
- **Relevance** (10% weight): Industry-specific

**Total Score**: Weighted average (0-100)

## Model Comparison

| Model | Size | Speed | Accuracy | Best For |
|-------|------|-------|----------|----------|
| **llama3.2** | 2GB | Medium | Good | Recommended |
| phi3 | 2GB | Fast | Good | Quick analysis |
| mistral | 4GB | Slow | Better | Higher accuracy |
| llama3.1:8b | 8GB | Slower | Best | Most accurate |

## Usage Example

```php
require_once 'includes/class-ai-content-analyzer.php';

// Get scraped items from database
$items = get_scraped_content();

// Analyze
$analyzer = new RawWire_AI_Content_Analyzer();
$top_10 = $analyzer->analyze_batch($items, 10);

// Display top findings
foreach ($top_10 as $finding) {
    echo $finding['original']['title'];
    echo " (Score: {$finding['score']}/100)";
    echo $finding['reasoning'];
}
```

## Performance

- **Quick Filter**: ~1ms per item (keyword check)
- **AI Analysis**: ~2-5 seconds per item
- **Batch of 50 items**: ~2-4 minutes total
- **Parallelizable**: Can run multiple instances

## Cost Comparison

| Solution | Cost | Rate Limits |
|----------|------|-------------|
| **Ollama (Local)** | **$0** | None |
| OpenAI GPT-4 | $0.03/1K tokens | Usage-based |
| Anthropic Claude | $0.025/1K tokens | Usage-based |
| Google Gemini | Free tier limited | 60 req/min |

**Ollama wins**: No costs, no limits, data stays private.

## Automation

### Daily Analysis Cron Job

```php
// In WordPress cron
add_action('rawwire_daily_analysis', function() {
    // Get new scraped content
    $items = get_unanalyzed_content();
    
    // Analyze
    $analyzer = new RawWire_AI_Content_Analyzer();
    $findings = $analyzer->analyze_batch($items, 10);
    
    // Store top findings
    foreach ($findings as $finding) {
        if ($finding['score'] >= 70) {
            store_as_featured($finding);
        }
    }
});

// Schedule daily at 2 AM
if (!wp_next_scheduled('rawwire_daily_analysis')) {
    wp_schedule_event(strtotime('2:00 AM'), 'daily', 'rawwire_daily_analysis');
}
```

## Troubleshooting

### Ollama not accessible
```bash
# Check if running
docker ps | grep ollama

# Restart if needed
docker-compose restart ollama
```

### Model not found
```bash
# List installed models
docker exec raw-wire-core-ollama-1 ollama list

# Pull if missing
docker exec raw-wire-core-ollama-1 ollama pull llama3.2
```

### Slow performance
- Use smaller model (phi3)
- Reduce batch size
- Add more RAM to Docker
- Use GPU if available (requires nvidia-docker)

## Advanced: GPU Acceleration

If you have an NVIDIA GPU:

```yaml
# docker-compose.yml
  ollama:
    image: ollama/ollama:latest
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: 1
              capabilities: [gpu]
```

**10-50x faster** with GPU! 🚀

## Privacy

- All processing happens locally
- No data sent to external APIs
- No logs transmitted
- Full control of your data

## Next Steps

1. ✅ Test AI analysis
2. Integrate with scraper workflow
3. Build dashboard UI for findings
4. Add user feedback system
5. Fine-tune scoring weights
