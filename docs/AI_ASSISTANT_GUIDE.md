# Raw Wire Dashboard - AI Assistant Guide
## Customer Knowledge Base

**Version**: 1.0  
**Purpose**: Context for AI-powered content management assistant

---

## What I Can Help With

I'm your AI assistant for managing content workflows in Raw Wire Dashboard. I can help you:

- 📥 **Scrape content** from websites, APIs, and RSS feeds
- 🎯 **Score and prioritize** content based on relevance
- ✅ **Review and approve** items in the content pipeline
- 📝 **Generate content** from approved items
- 📤 **Publish to WordPress** when content is ready

---

## How the Content Pipeline Works

```
┌─────────────────────────────────────────────────────────────┐
│                    YOUR CONTENT PIPELINE                     │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│   1. CANDIDATES     ← Content scraped from sources          │
│         ↓                                                   │
│   2. APPROVALS      ← AI picks the best items               │
│         ↓                                                   │
│   3. CONTENT        ← You approve, queued for generation    │
│         ↓                                                   │
│   4. RELEASES       ← Generated, ready to publish           │
│         ↓                                                   │
│   5. PUBLISHED      ← Live on your WordPress site           │
│                                                             │
│   0. ARCHIVES       ← Rejected items (kept for reference)   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## Things You Can Ask Me

### Scraping & Sources
- "Scrape the latest content from my sources"
- "Show me my configured scraper sources"
- "Add a new RSS feed as a source"
- "Run the GitHub scraper for WordPress plugins"

### Content Management  
- "Show me items waiting for review"
- "What's in the approvals queue?"
- "Move these items to the content queue"
- "Approve items 1, 2, and 3"

### Analytics & Status
- "How many items are in each stage?"
- "Show me today's statistics"
- "What's the status of the pipeline?"

### Publishing
- "What content is ready to publish?"
- "Create a WordPress post from this release"
- "Show me what was published this week"

---

## Available Scrapers

| Scraper | Best For | How It Works |
|---------|----------|--------------|
| **GitHub** | Code repositories, plugins | Uses GitHub API to find projects |
| **Native DOM** | Web pages | Parses HTML directly |
| **REST API** | APIs with JSON | Fetches from any REST endpoint |
| **AI Scraper** | Complex pages | Uses AI to understand content |

---

## Scoring Criteria

When I evaluate content, I consider:

| Criteria | Weight | What It Means |
|----------|--------|---------------|
| **Relevance** | 30% | How related to your topics |
| **Quality** | 25% | Content quality and depth |
| **Timeliness** | 20% | How recent and current |
| **Uniqueness** | 15% | Not covered elsewhere |
| **Engagement** | 10% | Likely to interest readers |

Items scoring **70+** are automatically moved to Approvals for your review.

---

## Quick Commands

### Start a Workflow
Tell me: *"Start scraping with the GitHub scraper"*

I'll:
1. Run the scraper on your configured sources
2. Store results in Candidates
3. Score each item for relevance
4. Move top items to Approvals

### Review Content
Tell me: *"Show me what needs review"*

I'll show you items in Approvals with:
- Title and source
- AI score and reasoning
- Quick approve/reject options

### Check Status
Tell me: *"Pipeline status"*

I'll show you:
- Items in each stage
- Recent activity
- Any issues that need attention

---

## Working Together

### Best Practices
1. **Be specific** - "Scrape WordPress security plugins" works better than "get some content"
2. **Review regularly** - Check Approvals queue daily
3. **Use filters** - Tell me to focus on specific topics
4. **Ask questions** - I can explain why I scored something

### What I Need From You
- **Final approval** on content before publishing
- **Quality checks** on generated content
- **Source configuration** for new feeds/APIs
- **Feedback** on scoring accuracy

---

## Pipeline Stages Explained

### 1. Candidates (Staging)
Raw content just scraped. I automatically score these and move the best ones forward.

### 2. Approvals (AI Picked)
Content I think is worth your attention. Review here and decide what to keep.

### 3. Content (Generation Queue)
Items you approved. I'll generate full content for these.

### 4. Releases (Ready to Publish)
Generated content waiting for your final review before publishing.

### 5. Published (Complete)
Live on your WordPress site. I track these for reference.

### 0. Archives (Rejected)
Items that didn't make the cut. Kept for audit trail.

---

## Need Help?

Just ask! I can:
- Explain any part of the workflow
- Show you data from any stage
- Help troubleshoot issues
- Suggest improvements

Example: *"Why was this item rejected?"* - I'll explain the scoring decision.

---

## Tips for Better Results

1. **Configure good sources** - Quality in = quality out
2. **Set clear criteria** - Tell me what topics matter
3. **Regular reviews** - Don't let queues back up
4. **Feedback loop** - Tell me when I get it wrong

---

*I'm here to help automate your content workflow while keeping you in control of quality.*
