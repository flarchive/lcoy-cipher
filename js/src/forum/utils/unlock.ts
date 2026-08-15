import app from 'flarum/forum/app';

export interface UnlockResponse {
  success: boolean;
  html?: string;
  error?: string;
}

/**
 * Resolve the id of the post that contains a locked block.
 *
 * The server embeds it via data-post-id; we fall back to walking up to the
 * post element (PostStream renders posts with data-id).
 */
export function getPostId(box: HTMLElement): number | null {
  if (box.dataset.postId) {
    const parsed = parseInt(box.dataset.postId, 10);
    if (Number.isInteger(parsed)) return parsed;
  }

  const post = box.closest<HTMLElement>('.Post');

  if (post?.dataset.id) {
    const parsed = parseInt(post.dataset.id, 10);
    if (Number.isInteger(parsed)) return parsed;
  }

  return null;
}

function sessionKey(postId: number, cipherId: string): string {
  return `lcoy-cipher:${postId}:${cipherId}`;
}

/**
 * The password remembered for a block, if any (current session only).
 */
export function savedPassword(postId: number, cipherId: string): string | null {
  return sessionStorage.getItem(sessionKey(postId, cipherId));
}

/**
 * Ask the server to verify the password and swap the locked card for the
 * rendered content — no page reload required. The returned HTML is produced by
 * the s9e renderer (escaped text, whitelisted tags only), so it is safe to
 * insert into the page.
 */
export async function unlockBlock(postId: number, cipherId: string, password: string): Promise<void> {
  const response = await app.request<UnlockResponse>({
    method: 'POST',
    url: `${app.forum.attribute('apiUrl')}/resource/unlock`,
    body: { postId, bbcodeIndex: cipherId, password },
    errorHandler: () => {},
  });

  sessionStorage.setItem(sessionKey(postId, cipherId), password);

  const box = document.querySelector<HTMLElement>(`.Cipher-box-locked[data-cipher-id="${cipherId}"]`);

  // The block may have been replaced by a redraw in the meantime.
  if (!box || !box.isConnected) return;

  const wrapper = document.createElement('div');
  wrapper.className = 'Cipher-box-content';
  wrapper.innerHTML = response.html || '';

  box.replaceWith(wrapper);
}
