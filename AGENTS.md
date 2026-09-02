## Not to do in Codes
1. make code as clear , easy to understand as possible.
2. before making new function, search if it is already existed or similar function existed and use that.
3. class related variables should have easy to understand naming. e.g tenantService for TenantService class.
4. don't overkill ,verify first.
5. don't ever try to add or fix something i didn't ask.just prompt as suggestion after task finished.
6. dont run test if u r not asked to.

## UI Redesign Workflow

When the user asks to redesign a page or work with UI:
Only do the following if user didn't give you reference UI or UI description.
1. Inspect the existing Blade page/component using filesystem access.
2. Identify:
   - page purpose
   - fields
   - buttons
   - tables
   - modals
   - filters
   - loading states
   - empty states
   - validation errors
   - API request payloads
   - API response shape
   - permission/feature flag behavior
   - current user workflow

3. Build a Stitch design prompt containing:
   - screen purpose
   - data request/response examples
   - UI workflow
   - layout requirements
   - design style
   - Tailwind-friendly structure
   - responsive behavior

4. Use Stitch MCP to generate the redesigned UI.

5. Download or fetch the generated HTML resource from Stitch.

6. Use the Stitch HTML only as visual/UI reference.

7. Implement the page in the existing codebase:
   - keep existing API calls
   - keep existing hooks
   - keep existing validation
   - keep route guards
   - keep permission checks
   - keep business logic
   - only update layout, components, className, spacing, and visual hierarchy

8. After implementation:
   - run type check/lint if available
   - summarize changed files
   - mention any UI behavior preserved
   - mention any Stitch design assumptions

## Do Not
- Do not replace logic with raw Stitch HTML.
- Do not remove API integration.
- Do not change backend endpoints.
- Do not change permission logic.
- Do not change validation rules unless explicitly requested.
