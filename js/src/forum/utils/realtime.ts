import app from 'flarum/forum/app';
import { attemptAutoUnlock } from './autoUnlock';
import { getPostId } from './unlock';

export interface CipherBlockStatus {
  id: string;
  reqs: Record<string, { met: boolean; message: string }>;
  html?: string;
}

export interface CipherStatusResponse {
  success: boolean;
  blocks?: CipherBlockStatus[];
  error?: string;
}

/**
 * Fetch the current condition status of every protected block in a post.
 */
export async function fetchPostStatus(postId: number): Promise<CipherBlockStatus[]> {
  const response = await app.request<CipherStatusResponse>({
    method: 'GET',
    url: `${app.forum.attribute('apiUrl')}/cipher/status?postId=${postId}`,
    errorHandler: () => {},
  });

  return response?.success ? response.blocks ?? [] : [];
}

/**
 * Apply a status payload to the locked card of a block by patching the ✓/✗
 * checklist in place (data attributes + icon classes + message text).
 *
 * Only data attributes and text are touched — never the node tree — so this
 * can't race with Mithril's redraw cycle.
 */
export function applyBlockStatus(block: CipherBlockStatus): void {
  const box = document.querySelector<HTMLElement>(`.Cipher-box-locked[data-cipher-id="${block.id}"]`);

  if (!box) return;

  Object.entries(block.reqs ?? {}).forEach(([key, status]) => {
    // Attribute names are lowercased by the browser when HTML is parsed, and
    // the dataset accessor for camel-cased keys (e.g. followDiscussion) then
    // doesn't line up. getAttribute/setAttribute are case-insensitive, so use
    // them for both reading and writing to stay consistent.
    box.setAttribute(`data-cipher-req-${key.toLowerCase()}`, status.met ? '1' : '0');
    box.setAttribute(`data-cipher-msg-${key.toLowerCase()}`, status.message);

    const row = box.querySelector<HTMLElement>(`.Cipher-box-req--${key}`);

    if (!row) return;

    const icon = row.querySelector<HTMLElement>('i');

    if (icon) {
      icon.className = `fas ${status.met ? 'fa-check' : 'fa-times'} Cipher-req-icon ${
        status.met ? 'Cipher-req-icon--met' : 'Cipher-req-icon--unmet'
      }`;
    }

    const text = row.querySelector<HTMLElement>('span');

    if (text) {
      text.textContent = status.message;
    }
  });
}

/**
 * Refresh the locked-card checklists of a post from the status API.
 *
 * If any block reports that its `time` gate has been reached (html present),
 * the whole post is re-fetched through the Flarum store and redrawn — this is
 * how a locked card becomes its public content without touching the DOM
 * behind Mithril's back.
 */
export async function refreshPostStatus(postId: number): Promise<void> {
  const blocks = await fetchPostStatus(postId);

  blocks.forEach(applyBlockStatus);

  if (blocks.some((block) => block.html)) {
    await refetchPost(postId);
  }
}

/**
 * Force the forum store to re-pull a post (the API renders it again, so a
 * time-gated block whose gate has passed comes back as plain content) and
 * redraw through Mithril.
 */
async function refetchPost(postId: number): Promise<void> {
  const payload = await app.request<any>({
    method: 'GET',
    url: `${app.forum.attribute('apiUrl')}/posts/${postId}`,
    errorHandler: () => {},
  });

  if (payload) {
    app.store.pushPayload(payload);
    m.redraw();

    // The redraw replaced the DOM; re-apply session unlocks so blocks the
    // visitor already unlocked by password don't look locked again.
    attemptAutoUnlock();
  }
}

// Coalesce bursts of events (e.g. several likes in quick succession) into a
// single status fetch per post.
const pendingRefreshes = new Map<number, ReturnType<typeof window.setTimeout>>();

function scheduleRefresh(postId: number): void {
  const existing = pendingRefreshes.get(postId);

  if (existing) window.clearTimeout(existing);

  pendingRefreshes.set(
    postId,
    window.setTimeout(() => {
      pendingRefreshes.delete(postId);
      refreshPostStatus(postId).catch(() => {});
    }, 400)
  );
}

/**
 * Subscribe to the forum's WebSocket channel (flarum-pusher / flarum-realtime)
 * and refresh affected locked cards when the server reports a change, e.g. a
 * minlikes-gated post was liked by someone else.
 */
export function setupRealtimeUpdates(): void {
  const pusher = (app as any).pusher;

  // flarum-pusher exposes `app.pusher` as a promise; other realtime extensions
  // expose a compatible socket binding. If none is available, silently skip.
  if (!pusher || typeof pusher.then !== 'function') return;

  pusher.then((binding: any) => {
    if (!binding?.pusher?.bind) return;

    binding.pusher.bind('cipherPostUpdate', (data: { postId?: number }) => {
      if (typeof data?.postId === 'number') {
        scheduleRefresh(data.postId);
      }
    });
  });
}

/**
 * For time-gated blocks, schedule a one-shot refresh at their unlock
 * timestamp instead of polling. When it fires, the post is re-fetched and the
 * card becomes its (now public) content.
 */
export function setupTimeUnlocks(): void {
  document.querySelectorAll<HTMLElement>('.Cipher-box-locked[data-cipher-target]').forEach((box) => {
    const target = parseInt(box.dataset.cipherTarget || '', 10);
    const postId = getPostId(box);

    if (!Number.isInteger(target) || postId == null || box.dataset.cipherTimerScheduled) return;

    box.dataset.cipherTimerScheduled = '1';

    const delay = Math.max(0, target * 1000 - Date.now());

    // setTimeout caps at ~24.8 days; a longer delay means the card will be
    // refreshed on the next page load anyway.
    if (delay > 2147483647) return;

    window.setTimeout(() => {
      delete box.dataset.cipherTimerScheduled;
      refreshPostStatus(postId).catch(() => {});
    }, delay);
  });
}
