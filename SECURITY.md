# Security Policy

Please **do not report security vulnerabilities through public GitHub issues, pull requests or discussions.** A public report exposes every user of the library before a fix is available.

## How Doctrine approaches security

Doctrine operates very close to your database and, as such, needs to make assumptions about SQL injection. It is vital that you understand how Doctrine approaches security, because we cannot protect you from SQL injection. Please read the security chapters of the documentation to understand the assumptions we make:

- [DBAL Security page](https://www.doctrine-project.org/projects/doctrine-dbal/en/stable/reference/security.html)
- [ORM Security page](https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/security.html)
- [security.rst in this repository](docs/en/reference/security.rst)

## Reporting a vulnerability

**Preferred: GitHub private vulnerability reporting**

1. Open the [Security tab](https://github.com/doctrine/orm/security) of this repository.
2. Click **Report a vulnerability**, or go straight to the [report form](https://github.com/doctrine/orm/security/advisories/new).
3. Describe the problem and submit.

Your report is visible only to the maintainers. It lets us discuss the issue with you, prepare a fix in private and publish a security advisory once patched releases are available.

**Fallback: email**

Only if you cannot use GitHub, send your report to security@doctrine-project.org. Emails sent to this address are forwarded to the Doctrine core team's private mailing list. See also the [Doctrine security reporting guidelines](https://www.doctrine-project.org/policies/security.html#reporting).

Whichever way you report, the following details help us a lot:

- the affected version(s) of the ORM, and of PHP, `doctrine/dbal`, the database platform and the driver if relevant
- a description of the problem and its impact
- a minimal way to reproduce it
- a suggested fix, if you have one

## What is a security issue?

A security issue is a flaw in the ORM itself that lets an attacker compromise the confidentiality, integrity or availability of an application, **even though that application uses the ORM as documented**, including the assumptions described in the security documentation above. Examples:

- **Injection:** user-controlled input is interpreted as DQL or SQL (for example through the query builder, criteria, pagination or identifier handling) where the ORM treats it as a plain value or parameter.
- **Silently weakened protection:** a bug that causes a security-relevant configuration, such as an SQL filter or a locking mode, to be dropped or ignored, so that the application's intended restriction no longer applies.
- **Unsafe instantiation or code execution:** the ORM instantiates arbitrary classes, or generated code (proxies, hydrators) can be made to include attacker-controlled code, based on data from the database or other untrusted input.
- **Unintended data access:** the ORM reads, returns or writes rows or fields the application did not ask for, for example because a condition is dropped or an association resolves to the wrong entity.
- **Denial of service:** small, crafted input causes disproportionate resource consumption inside the ORM.

The following are usually **not** security issues:

- Ordinary bugs with no security consequence. Please use the [issue tracker](https://github.com/doctrine/orm/issues) for these.
- An application passing unvalidated input to an API that is documented to accept raw DQL or SQL, such as native queries or string-built DQL. This is the SQL injection responsibility described in the security documentation.
- Vulnerabilities in the database server, the database driver, `doctrine/dbal` or other dependencies. Please report those to the respective projects; for DBAL, see its [security policy](https://github.com/doctrine/dbal/security/policy).
- Insecure database deployment, such as missing authentication or an exposed port.

**If you are unsure, report privately.** We would much rather triage a report that turns out to be a regular bug than have a vulnerability discussed in public. If we conclude that it is not a vulnerability, we will tell you, and you are then welcome to open a public issue.

## Reported publicly by mistake?

If you have already opened a public issue or pull request for something that may be a vulnerability, please [report it privately](https://github.com/doctrine/orm/security/advisories/new) and reference it. We may edit, hide or lock the public item and continue in private. Please do not add further exploit details in the meantime.

## What happens next

We follow the process described in the [Doctrine security policy](https://www.doctrine-project.org/policies/security.html): we confirm the issue, acknowledge your report, prepare a patch, release fixed versions and publish an advisory crediting you, unless you prefer to remain anonymous. Please do not disclose the issue publicly until we have published the fix.
