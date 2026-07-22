# Third-Party Notices

Meerkat is commercial software licensed under the [Meerkat Commercial License](LICENSE.md). Portions of Meerkat's compiled assets include third-party software governed by the separate terms below. Those terms apply only to the identified third-party software and do not change the license for Meerkat.

## Software included in compiled assets

### Axios

The Meerkat Control Panel JavaScript bundle includes [Axios](https://github.com/axios/axios), with the resolved version recorded in `package-lock.json`.

Copyright (c) 2014-present Matt Zabriskie & Collaborators

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

## Dependencies installed separately

Meerkat declares the following direct PHP runtime dependencies. Composer obtains them as separate packages; their code is not copied into the Meerkat distribution, and each installed package includes or identifies its own license terms.

| Package | Declared license | Purpose |
| --- | --- | --- |
| `statamic/cms` | Proprietary | Required Statamic platform |
| `league/csv` | MIT | CSV export support |
| `spatie/invade` | MIT | Controlled access to augmented Statamic data |
| `ueberdosis/tiptap-php` | MIT | Tiptap document and link handling |

Composer also installs transitive dependencies of these packages. Their resolved versions and declared licenses can be inspected with:

```bash
composer licenses --no-dev
```

Two transitive declarations deserve clarification:

- `nette/schema` and `nette/utils` offer BSD-3-Clause or GPL license choices. Meerkat's audit selects BSD-3-Clause.
- `james-heinrich/getid3` is pulled in by Statamic and offers GPL, LGPL, or MPL-2.0 choices. Meerkat's audit selects MPL-2.0 for that separately installed package. Meerkat does not copy or modify getID3 code.

If you redistribute a complete installation containing `vendor/`, you are responsible for preserving the license and notice files supplied by those Composer packages. The official Meerkat package does not include `vendor/`.

## Development dependencies

Build and test dependencies are not included as runtime packages in the Meerkat distribution. Their resolved versions and license identifiers are recorded in `package-lock.json` and, when dependencies are installed, can be checked with:

```bash
npm run audit:licenses
composer audit:licenses
```
