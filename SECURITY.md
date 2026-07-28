# Security policy

The latest pre-release/minor line receives security fixes until a stable
support policy is announced.

Report vulnerabilities privately to `support@appitstudio.com`. Do not open a public
issue or include bearer tokens, production customer data, or full request
bodies. Include the affected package version, Laravel/PHP versions, impact, and
a minimal redacted reproduction. Receipt will be acknowledged and remediation
coordinated before public disclosure.

The SDK stores credentials only in host configuration, uses explicit team
scope, and redacts transport failures. Applications remain responsible for
secret rotation, secure sessions, consent policy, queue security, and
least-privilege token issuance.
