# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 0.x.x   | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security vulnerability in this SDK, please report it by emailing security@muxi.ai.

Please include:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Any suggested fixes (optional)

We will acknowledge receipt within 48 hours and provide a detailed response within 7 days.

## Security Best Practices

When using this SDK:

1. **Never commit credentials** - Use environment variables or secure secret management
2. **Validate webhook signatures** - Always verify `X-Muxi-Signature` headers
3. **Use HTTPS** - Always connect to MUXI servers over HTTPS in production
4. **Keep dependencies updated** - Regularly update the SDK to get security patches
