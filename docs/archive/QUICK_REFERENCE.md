# RawWire Dashboard - Quick Testing Reference

## 🚀 Quick Start Commands

### Run Complete Test Suite
```bash
cd wp-content/plugins/raw-wire-dashboard
php seed-test-data.php
```

### Seed Fresh Data
```bash
php seed-test-data.php --clear --seed-only
```

### Test Existing Data
```bash
php seed-test-data.php --test-only --verbose
```

---

## 📊 Expected Output

```
================================================================================
                             TEST SUMMARY REPORT
================================================================================
Data Seeding:
  Generated: 50
  Stored: 50
  Errors: 0

REST API Tests:
  ✓ get_content
  ✓ get_stats
  ✓ content_filters
  ✓ pagination

[✓] Approval Workflow: PASSED
[✓] Snooze Workflow: PASSED

Dashboard Display Checks:
  ✓ complete_fields
  ✓ scored_items
  ✓ source_variety

================================================================================
FINAL RESULT: 11/11 tests passed (100.0%)
================================================================================
```

---

## 🔗 API Endpoints

### Get Content (Pending Items)
```bash
curl "https://yoursite.com/wp-json/rawwire/v1/content?status=pending"
```

### Get High-Scoring Items
```bash
curl "https://yoursite.com/wp-json/rawwire/v1/content?min_relevance=80&limit=10"
```

### Get Dashboard Stats
```bash
curl "https://yoursite.com/wp-json/rawwire/v1/stats"
```

### Approve Content (Auth Required)
```bash
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/content/approve" \
  -H "Content-Type: application/json" \
  -u admin:password \
  -d '{"content_id": 123}'
```

### Generate Simulated Data (Auth Required)
```bash
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/fetch-data" \
  -H "Content-Type: application/json" \
  -u admin:password \
  -d '{"simulate": true, "count": 50, "shock_level": "mixed"}'
```

---

## 📋 Manual Testing Checklist

**After running seed script:**

- [ ] Access dashboard: `/wp-admin/admin.php?page=rawwire-dashboard`
- [ ] Verify 50 items displayed
- [ ] Test "Pending" filter
- [ ] Test "Approved" filter
- [ ] Test search box
- [ ] Click "Approve" on pending item
- [ ] Verify item status changed
- [ ] Click "Snooze" on another item
- [ ] Verify item hidden
- [ ] Check stats counter updated
- [ ] Test pagination (navigate pages)
- [ ] Test score sorting
- [ ] Test date range filter
- [ ] Refresh data button works

---

## 🎯 Key Files

| File | Purpose |
|------|---------|
| `seed-test-data.php` | Main testing script |
| `validate-code.php` | Pre-deployment validation |
| `TESTING_GUIDE.md` | Complete documentation |
| `DATA_SEEDING_STATUS.md` | Status summary |

---

## 🐛 Troubleshooting

### "Cannot find WordPress installation"
```bash
# Run from plugin directory
cd wp-content/plugins/raw-wire-dashboard
php seed-test-data.php
```

### "Required dependencies not found"
```bash
# Activate plugin first
wp plugin activate raw-wire-dashboard
```

### Check error logs
```bash
tail -f wp-content/plugins/raw-wire-dashboard/logs/activity.log
tail -f wp-content/debug.log
```

---

## ✅ Success Metrics

**All tests passed when you see:**
- ✅ 50 items generated and stored
- ✅ 8 REST endpoints responding
- ✅ Approval workflow working
- ✅ Snooze workflow working
- ✅ Score distribution across ranges
- ✅ Dashboard displaying data
- ✅ Stats accurate
- ✅ Filters working

**Ready for production when:**
- Staging tests: 11/11 passed (100%)
- No PHP errors in logs
- Dashboard UI functional
- API response times < 500ms
- Database queries optimized

---

## 📖 Documentation

- **Testing Guide:** [TESTING_GUIDE.md](TESTING_GUIDE.md)
- **Deployment:** [DEPLOYMENT_READY_v1.0.13.md](DEPLOYMENT_READY_v1.0.13.md)
- **API Reference:** [API_DOCUMENTATION.md](API_DOCUMENTATION.md)
- **Architecture:** [PLUGIN_ARCHITECTURE.md](PLUGIN_ARCHITECTURE.md)

---

## 🎉 Next Steps

1. ✅ Code validation complete (55 PHP files valid)
2. ⏳ Deploy to staging WordPress
3. ⏳ Run testing script
4. ⏳ Manual UI verification
5. ⏳ Performance check
6. ⏳ Production deployment

**Time to Production:** ~1 hour of testing on staging
