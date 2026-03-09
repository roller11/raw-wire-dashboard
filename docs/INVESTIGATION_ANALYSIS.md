# Party Investigator: Data Quality Assessment & BI Potential

**Date:** February 2025  
**Dataset:** 10 construction companies investigated via Grok/OpenClaw  
**Purpose:** Assess investigation quality, schema consistency, and business intelligence potential

---

## 1. Dataset Summary

| Company | File Size | Quality |
|---------|-----------|---------|
| Turner Construction | 6,708 bytes | Excellent |
| Hensel Phelps | 7,261 bytes | Excellent |
| Skanska USA | 6,341 bytes | Excellent |
| Clark Construction | 5,759 bytes | Good |
| Webcor Builders | 5,403 bytes | Good |
| AECOM | 5,192 bytes | Good |
| Suffolk Construction | 4,882 bytes | Good |
| McCarthy Building | 4,818 bytes | Good |
| DPR Construction | 3,813 bytes | Acceptable |
| Swinerton Builders | (original) | Excellent |

**Average file size:** ~5.5KB per investigation  
**Total collected data:** ~55KB of structured intelligence

---

## 2. Schema Consistency Analysis

### 2.1 Core Categories (Present in 100% of investigations)

| Category | Content | Confidence |
|----------|---------|------------|
| `company_overview` | Description, location, size, revenue | High |
| `contacts` | Leadership names, email patterns, phone | Medium-High |
| `projects` | Current/recent builds, status, locations | Medium |
| `relationships` | Architects, engineers, subs, clients | Medium |
| `gatherings` | Events, conferences, mixers | Medium |
| `affiliations` | Association memberships | High |
| `community` | CSR, mentorship, outreach | Medium |
| `edge_intel` | News, trends, risks, awards | Medium |
| `entry_points` | Approach strategies | High |

### 2.2 Schema Variations

**Consistent across all:**
- Confidence markers (`confirmed`, `inferred`, `speculative`)
- Nested structures for contacts (leadership array, general_contact object)
- Projects with name/description/status per item

**Minor variations:**
- Some use `current_or_recent` vs `current_notable` for projects
- Contact patterns vary in depth (some include email regex, others general)
- Relationships sometimes flat array, sometimes nested by type

### 2.3 Confidence Distribution

Based on manual review:
- **Confirmed:** Company overviews, affiliations, general contact info
- **Inferred:** Leadership names, specific projects, partner relationships
- **Speculative:** Contact email patterns, event participation, upcoming projects

---

## 3. Data Quality Observations

### 3.1 Strengths

1. **Rich ecosystem mapping** - Every investigation returns actionable relationship data
2. **Entry point specificity** - Concrete approaches (LinkedIn targets, RFP portals, events)
3. **Confidence transparency** - Clear marking of data reliability
4. **Association coverage** - Consistent AGC/ABC/DBIA/USGBC affiliations
5. **Project intelligence** - Current builds with context and status

### 3.2 Deficiencies Identified

1. **Project currency** - Some projects listed as "ongoing" may be completed
2. **Contact verification** - Email patterns inferred but not validated
3. **Leadership turnover** - Names may be stale (6-12 month lag)
4. **Event dates** - Gatherings listed without specific dates
5. **Sub-relationships** - Subcontractor data sparse compared to architects

### 3.3 Recommended Improvements

| Issue | Solution | Implementation |
|-------|----------|----------------|
| Stale contacts | Web search verification loop | Add validation step |
| Project age | Cross-reference ENR/BD+C feeds | Second pass research |
| Event dates | Calendar API integration | Scrape association calendars |
| Contact validation | LinkedIn API for real-time | Premium subscription |
| Sub relationships | Permit database mining | Integrate permit data |

---

## 4. Business Intelligence Potential

### 4.1 What This Data Enables

The structured investigation data unlocks multiple BI applications beyond basic lead generation:

#### **Relationship Mapping**
- Build network graphs of who works with whom
- Identify architect-contractor partnerships
- Track subcontractor networks by region

#### **Event Intelligence**
- Aggregate industry gatherings by month/quarter
- Identify high-value networking opportunities
- Track which companies sponsor which events

#### **Project Tracking**
- Monitor active construction by region/sector
- Predict upcoming opportunities from planned projects
- Track contractor specialization trends

#### **Market Analysis**
- Company size/revenue comparisons
- Regional market share indicators
- Expansion pattern detection

#### **Approach Optimization**
- Score entry points by success likelihood
- Recommend optimal networking events
- Generate personalized outreach strategies

---

## 5. Small Business BI Tools Enabled

Based on this investigation capability, here are tools that could serve small contractors, suppliers, and service providers:

### 5.1 Immediate Opportunities

| Tool | Description | Value |
|------|-------------|-------|
| **Prospect Mapper** | Visual network of GC-architect-sub relationships | Find warm intros |
| **Event Calendar** | Aggregated industry events with company attendance | Network efficiently |
| **Project Radar** | Active projects by sector/region with team rosters | Time bids perfectly |
| **Contact Intelligence** | Verified decision-maker contacts with approach notes | Reduce cold outreach |

### 5.2 Advanced Analytics

| Tool | Description | Value |
|------|-------------|-------|
| **Partnership Predictor** | ML model for likely partnerships based on history | Target compatible GCs |
| **Win Rate Analyzer** | Track bid success by company/sector | Focus efforts |
| **Market Pulse** | Regional construction activity trends | Strategic planning |
| **Competitor Intel** | Peer company analysis and differentiation | Positioning |

### 5.3 Workflow Automation

| Tool | Description | Value |
|------|-------------|-------|
| **Auto-Investigator** | Scheduled deep dives on new leads | Save research time |
| **Alert System** | Notify when target companies win projects | Timely outreach |
| **CRM Enrichment** | Auto-populate contact records with intel | Data quality |
| **Proposal Generator** | Customize proposals based on company profile | Higher close rate |

---

## 6. Industry Dashboard Concept

### 6.1 Dashboard Views

#### **Network View**
```
                    [GC: Turner Construction]
                           |
         +--------+--------+--------+
         |        |        |        |
    [Arch: Gensler]  [Eng: Arup]  [Sub: Helix Electric]
         |        |        |        |
         +--[Project: Lucas Museum]--+
```

Interactive graph showing:
- Click any node to see full investigation
- Edge thickness = relationship frequency
- Color = company type (GC/arch/eng/sub)

#### **Calendar View**
```
February 2025
─────────────────────────────────────
| Event                    | Companies Attending |
| AGC SoCal Golf Outing    | Turner, Skanska     |
| DBIA Pacific Conference  | Clark, AECOM        |
| ENR CA Top Projects      | All 10              |
```

#### **Project Pipeline**
```
Active Projects in Southern California
────────────────────────────────────────
| Project              | GC      | Phase    | Est. Value |
| Lucas Museum         | Turner  | Active   | $1B+       |
| LAX Terminal         | Skanska | Planning | $500M      |
| USC Med Expansion    | Turner  | Active   | $300M      |
```

#### **Company Cards**
Quick-view cards with:
- Company tier (based on revenue/project volume)
- Key contacts with approach notes
- Top 3 entry points
- Active project count
- Last updated timestamp

### 6.2 Filters & Drill-Down

- **By Region:** SoCal / NorCal / Bay Area
- **By Sector:** Healthcare / Education / Commercial / Public
- **By Size:** Enterprise / Mid-Market / Regional
- **By Relationship:** 1st degree / 2nd degree / New

---

## 7. Technical Requirements

### 7.1 Data Storage

Recommend PostgreSQL with JSONB for flexible schema:
```sql
CREATE TABLE investigations (
    id SERIAL PRIMARY KEY,
    company_name VARCHAR(255),
    raw_response JSONB,
    parsed_data JSONB,
    confidence_score DECIMAL,
    last_updated TIMESTAMP,
    source VARCHAR(50)
);

CREATE TABLE relationships (
    id SERIAL PRIMARY KEY,
    company_a_id INT REFERENCES investigations(id),
    company_b_id INT REFERENCES investigations(id),
    relationship_type VARCHAR(50),
    confidence VARCHAR(20),
    projects JSONB
);

CREATE TABLE events (
    id SERIAL PRIMARY KEY,
    event_name VARCHAR(255),
    event_date DATE,
    association VARCHAR(100),
    expected_attendees JSONB
);
```

### 7.2 Processing Pipeline

```
[New Lead] → [Investigation Queue] → [OpenClaw/Grok]
                                           ↓
                                    [Parse Response]
                                           ↓
                                    [Validate Data]
                                           ↓
                                    [Enrich (LinkedIn)]
                                           ↓
                                    [Store + Index]
                                           ↓
                                    [Update Dashboard]
```

### 7.3 Refresh Strategy

| Data Type | Refresh Frequency | Trigger |
|-----------|-------------------|---------|
| Company overview | Monthly | Manual or auto |
| Contacts | Weekly | Stale detection |
| Projects | Weekly | News monitoring |
| Events | Daily | Calendar scrape |
| Relationships | On investigation | New data |

---

## 8. Next Steps

### Immediate (This Week)
1. ✅ Complete batch investigations (done - 10 companies)
2. ⬜ Build parser to extract uniform schema from responses
3. ⬜ Store parsed data in PostgreSQL
4. ⬜ Create relationship extraction logic

### Short-Term (This Month)
5. ⬜ Design dashboard wireframes
6. ⬜ Build network visualization prototype
7. ⬜ Add project tracking view
8. ⬜ Implement event calendar scraping

### Medium-Term (Q2 2025)
9. ⬜ Contact validation pipeline
10. ⬜ LinkedIn enrichment integration
11. ⬜ Alert system for project wins
12. ⬜ CRM sync capabilities

---

## 9. Conclusion

The Party Investigator data quality is **production-ready** with minor improvements needed:

- **Schema consistency:** 90%+ across investigations
- **Actionable intelligence:** Present in every response
- **Business value:** High for relationship discovery and project tracking

This is **not just a lead generator**. The structured ecosystem data enables:
- Network analysis
- Event planning
- Project pipeline intelligence
- Market research
- Competitive analysis

The opportunity is a **construction industry intelligence platform** that gives small businesses access to enterprise-level market visibility.

---

*Analysis generated from 10 investigations totaling ~55KB of structured data.*
