# Contributing to Meerkat

Thanks for helping improve Meerkat. Bug reports, documentation corrections, tests, and focused pull requests are welcome.

Meerkat is source-available commercial software, not open-source software. Contributions may be incorporated into commercial releases distributed under the [Meerkat Commercial License](LICENSE.md).

## Before opening a pull request

1. Open an issue first for substantial features or architectural changes so the approach can be discussed.
2. Keep the change focused and avoid unrelated formatting or refactoring.
3. Add or update tests for behavior changes.
4. Run the relevant checks:

   ```bash
   composer test
   composer phpstan
   npm test
   npm run build
   ```

5. Update user-facing documentation when behavior, configuration, or public APIs change.

## Contribution license

You retain copyright in your original contribution. By intentionally submitting a contribution to this repository, you grant Stillat, LLC and recipients of software distributed by Stillat a perpetual, worldwide, non-exclusive, irrevocable, royalty-free license to reproduce, prepare derivative works of, publicly display, publicly perform, use, make, sell, offer for sale, import, distribute, sublicense, and relicense your contribution as part of or in connection with Meerkat and related products and services.

If your contribution includes a patent claim that you can license and that would necessarily be infringed by your contribution alone or by its combination with Meerkat, you grant Stillat, LLC and recipients of Meerkat a perpetual, worldwide, non-exclusive, irrevocable, royalty-free patent license to make, use, sell, offer for sale, import, and otherwise transfer your contribution as part of Meerkat. This patent license terminates for a recipient who initiates patent litigation alleging that the contribution or Meerkat infringes a patent.

By submitting a contribution, you represent that:

- you have the legal right to submit it and grant these permissions;
- if you created it in the course of employment or for another party, that party has authorized the submission and the permissions granted here;
- it is your original work, or you have clearly identified its source and license and have authority to include it;
- it does not knowingly contain confidential information, trade secrets, or code that is incompatible with Meerkat's commercial distribution; and
- you understand that Stillat may use, modify, distribute, sublicense, or relicense it commercially without an obligation to provide compensation.

To the extent permitted by law, you waive and agree not to assert moral rights, authors' rights, or similar rights in your contribution that would interfere with the permissions granted above. Where those rights cannot be waived, you agree not to enforce them against Stillat, its licensees, or recipients of Meerkat for an exercise of those permissions.

Stillat may require you to confirm these contribution terms through a repository workflow or separate contributor agreement before accepting a contribution. Do not submit a contribution if you do not agree to these terms.

Do not submit GPL, AGPL, or other copyleft-licensed code, generated code with unclear rights, or code copied from another project unless Stillat has approved it in advance.

## Security issues

Do not disclose a suspected vulnerability in a public issue. Use the repository's private security-reporting channel when available, or contact Stillat through [stillat.com/contact](https://stillat.com/contact).
