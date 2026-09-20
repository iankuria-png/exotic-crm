# Reusable lessons from prior checkpoints

- July lifecycle handover's not-started state was consumed by later implementation.
  `66371e69`, `d0dac577`, `7aaaf703`, `824c7671` and current restore/recovery services
  are the resumption evidence; retain the configurable, observable, reversible requirements.
- MCP knowledge-layer plan labels lag behind code: `50aa8921` through `8ebafdd5`
  implement the layer and staging. A client resource-discovery failure is a specific
  runtime/interface observation, not evidence that the entire feature needs building again.
- User/customer/visitor/client distinctions prevent ownership errors: follow the WP-owned
  contract linked in map.md and current CustomerProductService validation.
- Shared working trees require preserving the index and mixed-file edits. A task-owned
  path is not proof that every changed line belongs to that task.

When a long conversation needs a handover, link the next action and evidence; when it is
consumed, retain the useful decision/lesson and close or supersede the old task state.
Do not accumulate obsolete next-action lists. Validate new lessons against source before
promoting them to shared rules.
