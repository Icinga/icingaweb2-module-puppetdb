Tuning hints
============

On older PuppetDB versions fetching classes can become very slow. An
alternative would be using an SQL-based import for classes, fetching
just facts through the API and merging both import sources in a single
sync rule.

Sample query (does not work on newer PuppetDB versions):

```sql
SELECT
  c.certname,
  array_to_string(array_agg(r.title order by r.title ASC), ',')
  FROM catalog_resources r
  JOIN certname_catalogs c ON r.catalog = c.catalog
  JOIN certnames n ON n.name = c.certname AND n.deactivated IS NULL
 WHERE type = 'Class'
 GROUP BY c.certname
 ORDER BY c.certname;
```
