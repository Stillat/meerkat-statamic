---
id: '1779212445'
name: 'Code Reviewer'
email: 'codereviewer@example.com'
user_ip: '192.0.2.88'
user_agent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'
referer: 'http://meerkatmigrator.test/donuts'
page_url: 'http://meerkatmigrator.test/donuts'
published: true
internal_author_has_name: true
internal_author_has_email: true
spam: false
source: 'imported-from-disqus'
legacy_id: 'disqus-3818822'
---
Here's the snippet I was talking about:

```php
$donut = Donut::query()
    ->where('flavor', 'maple-bacon')
    ->where('in_stock', true)
    ->first();
```

If this returns null, you've got a supply chain problem.
