# Introduction

ForgeKin connects employers with freelancers. This API powers freelancer registration, employer onboarding, job posting, and admin management.

<aside>
    <strong>Base URL</strong>: <code>http://localhost:8000</code>
</aside>

    ## Welcome to the ForgeKin API

    This documentation provides everything you need to integrate with the ForgeKin platform.

    ### Authentication
    Most endpoints require a **Bearer token** obtained via the login endpoints. Include it in your requests as:
    ```
    Authorization: Bearer {YOUR_TOKEN}
    ```

    ### User Types
    - **Freelancer** — Registers, verifies email, manages profile/skills/experience
    - **Employer** — Registers (requires admin activation), posts and manages jobs
    - **Admin** — Manages users, roles, permissions, and job approvals

    <aside>As you scroll, you'll see code examples for working with the API in different programming languages in the dark area to the right (or as part of the content on mobile).
    You can switch the language used with the tabs at the top right (or from the nav menu at the top left on mobile).</aside>

