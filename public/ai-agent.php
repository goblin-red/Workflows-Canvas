<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo <<<'GUIDE'
Connect your AI agent to Goblin Canvas

Read the invitation code from the #code= fragment of the original link. The fragment is not sent in an HTTP request.
Send all operations to api.php next to this page. The op field names the operation.

1. POST JSON {"op":"ai.agent.connect","code":"CODE"}. Save the token for this connection and tell the user you are ready.
2. Send the token in the X-Goblin-AI-Agent header. Keep the invitation code and token out of chat and scheme content.
3. Poll for web chat messages with POST {"op":"ai.agent.take","wait":15}. If task is null, wait two seconds and retry.
4. Read messages and submit the requested JSON object with {"op":"ai.agent.finish","id":ID,"lease":"LEASE","data":{...}}. On failure send an error string in place of data. Canvas shows the reply and asks the user to confirm proposed edits.
5. For an explicit terminal task, use GET api.php?op=ai.agent.projects and GET api.php?op=ai.agent.scene with numeric projectId and parent or folder. A token scoped to a single scheme can omit these parameters.
6. For direct edits, POST ai.agent.edit with the same target, ifRev returned by ai.agent.scene, a unique opId starting with edit-, and commands. Reuse the same opId if a response is lost. Read the scene again after a conflict.

Direct editing commands:
- {"command":"block.add","ref":"b1","kind":"block","title":"Title","description":"Description"}
- {"command":"block.edit","block":42,"title":"New title","description":"New description"}
- {"command":"arrow.add","source":42,"target":"b1","kind":"flow","label":"Continue"}
- {"command":"arrow.remove","arrow":123}

Edit directly only when the user asks you to do so in the terminal. Submit web chat proposals through ai.agent.finish.
Check the accessible schemes again if the user changes the target. Stop polling when the connection is revoked.
GUIDE;
