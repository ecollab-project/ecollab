/**
 * ot-engine.js — Operational Transformation for collaborative text editing
 *
 * Implements the Jupiter/dOPT algorithm subset sufficient for a shared text editor:
 *   - Three operation types: retain, insert, delete (composable, transformable)
 *   - Client-side state machine: SYNC | AWAITING_ACK | AWAITING_ACK_WITH_BUFFER
 *   - Server-side transform: transforms any pending op against all ops committed
 *     since that op's base revision, producing a new op that applies cleanly
 *
 * This file is self-contained with zero dependencies.
 * It is consumed by collab-liveeditor.js (the UI layer).
 *
 * References:
 *   Nichols et al. 1995 "High-latency, low-bandwidth windowing in the Jupiter collaboration system"
 *   Operational Transformation FAQ (http://operational-transformation.github.io/)
 */

'use strict';

// ─────────────────────────────────────────────────────────────────────────────
// Op primitives  (immutable value objects)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * An Op is an array of components, each one of:
 *   { retain: N }          — skip N characters unchanged
 *   { insert: "str" }      — insert string at current position
 *   { delete: N }          — delete N characters at current position
 *
 * Invariant: an op always covers exactly baseLength characters of input
 * and produces exactly targetLength characters of output.
 */

const OT = (() => {

  // ── helpers ──────────────────────────────────────────────────────────────

  function baseLength(op) {
    return op.reduce((n, c) => n + (c.retain ?? 0) + (c.delete ?? 0), 0);
  }

  function targetLength(op) {
    return op.reduce((n, c) => n + (c.retain ?? 0) + (c.insert?.length ?? 0), 0);
  }

  /** Push a component, merging with tail if same type */
  function push(op, comp) {
    const last = op[op.length - 1];
    if (!last) { op.push(comp); return; }
    if (comp.retain !== undefined && last.retain !== undefined)
      { op[op.length - 1] = { retain: last.retain + comp.retain }; return; }
    if (comp.insert !== undefined && last.insert !== undefined)
      { op[op.length - 1] = { insert: last.insert + comp.insert }; return; }
    if (comp.delete !== undefined && last.delete !== undefined)
      { op[op.length - 1] = { delete: last.delete + comp.delete }; return; }
    op.push(comp);
  }

  // ── apply ────────────────────────────────────────────────────────────────

  /**
   * Apply op to string s, returning new string.
   * Throws if op is inconsistent with s.
   */
  function apply(s, op) {
    if (typeof s !== 'string') throw new TypeError('apply: s must be string');
    let idx = 0, out = '';
    for (const c of op) {
      if (c.retain !== undefined) {
        if (idx + c.retain > s.length) throw new RangeError('retain past end');
        out += s.slice(idx, idx + c.retain);
        idx += c.retain;
      } else if (c.insert !== undefined) {
        out += c.insert;
      } else if (c.delete !== undefined) {
        if (idx + c.delete > s.length) throw new RangeError('delete past end');
        idx += c.delete;
      }
    }
    out += s.slice(idx);
    return out;
  }

  // ── compose ──────────────────────────────────────────────────────────────

  /**
   * Compose two consecutive ops into one equivalent op.
   * Requires targetLength(a) === baseLength(b).
   */
  function compose(a, b) {
    if (targetLength(a) !== baseLength(b))
      throw new Error(`compose: length mismatch ${targetLength(a)} vs ${baseLength(b)}`);

    const result = [];
    let ai = 0, bi = 0;
    let aComp = a[ai], bComp = b[bi];

    const nextA = () => { ai++; aComp = a[ai]; };
    const nextB = () => { bi++; bComp = b[bi]; };

    while (aComp !== undefined || bComp !== undefined) {
      // b.delete eats a output
      if (bComp?.delete !== undefined) {
        push(result, { delete: bComp.delete });
        nextB(); continue;
      }
      // a.insert feeds b
      if (aComp?.insert !== undefined) {
        if (bComp?.retain !== undefined) {
          const n = Math.min(aComp.insert.length, bComp.retain);
          push(result, { insert: aComp.insert.slice(0, n) });
          aComp = aComp.insert.length > n ? { insert: aComp.insert.slice(n) } : undefined;
          bComp = bComp.retain > n ? { retain: bComp.retain - n } : undefined;
          if (aComp === undefined) nextA();
          if (bComp === undefined) nextB();
        } else if (bComp?.delete !== undefined) {
          // handled above
        } else {
          push(result, { insert: aComp.insert }); nextA();
        }
        continue;
      }

      // Preserve inserts from the second operation after the first op ends.
      if (bComp?.insert !== undefined) {
        push(result, { insert: bComp.insert });
        nextB();
        continue;
      }

      if (aComp === undefined || bComp === undefined) break;

      if (aComp.retain !== undefined && bComp.retain !== undefined) {
        const n = Math.min(aComp.retain, bComp.retain);
        push(result, { retain: n });
        aComp = aComp.retain > n ? { retain: aComp.retain - n } : undefined;
        bComp = bComp.retain > n ? { retain: bComp.retain - n } : undefined;
      } else if (aComp.delete !== undefined && bComp.retain !== undefined) {
        const n = Math.min(aComp.delete, bComp.retain);
        push(result, { delete: n });
        aComp = aComp.delete > n ? { delete: aComp.delete - n } : undefined;
        bComp = bComp.retain > n ? { retain: bComp.retain - n } : undefined;
      } else if (aComp.retain !== undefined && bComp.delete !== undefined) {
        const n = Math.min(aComp.retain, bComp.delete);
        push(result, { delete: n });
        aComp = aComp.retain > n ? { retain: aComp.retain - n } : undefined;
        bComp = bComp.delete > n ? { delete: bComp.delete - n } : undefined;
      } else if (aComp.delete !== undefined && bComp.delete !== undefined) {
        // both delete same region — a already consumed it, b has nothing
        const n = Math.min(aComp.delete, bComp.delete);
        aComp = aComp.delete > n ? { delete: aComp.delete - n } : undefined;
        bComp = bComp.delete > n ? { delete: bComp.delete - n } : undefined;
      }

      if (aComp === undefined) nextA();
      if (bComp === undefined) nextB();
    }

    return result;
  }

  // ── transform ─────────────────────────────────────────────────────────────

  /**
   * transform(a, b, side) → [a', b']
   *
   * Given two ops a and b that both start from the same document state,
   * produce a' such that apply(apply(doc, a), b') = apply(apply(doc, b), a').
   *
   * side ('left'|'right') breaks ties: when both ops insert at the same position,
   * 'left' goes first (used for the server's perspective).
   */
  function transform(a, b, side = 'left') {
    if (baseLength(a) !== baseLength(b))
      throw new Error(`transform: base length mismatch ${baseLength(a)} vs ${baseLength(b)}`);

    const a2 = [], b2 = [];
    let ai = 0, bi = 0;
    let aComp = a[ai], bComp = b[bi];

    const nextA = () => { ai++; aComp = a[ai]; };
    const nextB = () => { bi++; bComp = b[bi]; };

    while (aComp !== undefined || bComp !== undefined) {

      // ── both insert ───────────────────────────────────────────────────────
      if (aComp?.insert !== undefined && bComp?.insert !== undefined) {
        if (side === 'left') {
          push(a2, { retain: aComp.insert.length });
          push(b2, { insert: aComp.insert });
          nextA();
        } else {
          push(b2, { retain: bComp.insert.length });
          push(a2, { insert: bComp.insert });
          nextB();
        }
        continue;
      }

      // ── a inserts ─────────────────────────────────────────────────────────
      if (aComp?.insert !== undefined) {
        push(a2, { insert: aComp.insert });
        push(b2, { retain: aComp.insert.length });
        nextA(); continue;
      }

      // ── b inserts ─────────────────────────────────────────────────────────
      if (bComp?.insert !== undefined) {
        push(a2, { retain: bComp.insert.length });
        push(b2, { insert: bComp.insert });
        nextB(); continue;
      }

      if (aComp === undefined || bComp === undefined) break;

      // ── retain / retain ───────────────────────────────────────────────────
      if (aComp.retain !== undefined && bComp.retain !== undefined) {
        const n = Math.min(aComp.retain, bComp.retain);
        push(a2, { retain: n }); push(b2, { retain: n });
        aComp = aComp.retain > n ? { retain: aComp.retain - n } : undefined;
        bComp = bComp.retain > n ? { retain: bComp.retain - n } : undefined;
      }
      // ── delete / delete ───────────────────────────────────────────────────
      else if (aComp.delete !== undefined && bComp.delete !== undefined) {
        const n = Math.min(aComp.delete, bComp.delete);
        aComp = aComp.delete > n ? { delete: aComp.delete - n } : undefined;
        bComp = bComp.delete > n ? { delete: bComp.delete - n } : undefined;
      }
      // ── delete / retain ───────────────────────────────────────────────────
      else if (aComp.delete !== undefined && bComp.retain !== undefined) {
        const n = Math.min(aComp.delete, bComp.retain);
        push(a2, { delete: n });
        aComp = aComp.delete > n ? { delete: aComp.delete - n } : undefined;
        bComp = bComp.retain > n ? { retain: bComp.retain - n } : undefined;
      }
      // ── retain / delete ───────────────────────────────────────────────────
      else if (aComp.retain !== undefined && bComp.delete !== undefined) {
        const n = Math.min(aComp.retain, bComp.delete);
        push(b2, { delete: n });
        aComp = aComp.retain > n ? { retain: aComp.retain - n } : undefined;
        bComp = bComp.delete > n ? { delete: bComp.delete - n } : undefined;
      }

      if (aComp === undefined) nextA();
      if (bComp === undefined) nextB();
    }

    return [a2, b2];
  }

  // ── transformCursor ───────────────────────────────────────────────────────

  /**
   * Given a cursor position in doc-before-op, return its position in doc-after-op.
   */
  function transformCursor(cursor, op) {
    let pos = 0, newCursor = cursor;
    for (const c of op) {
      if (pos >= cursor) break;
      if (c.retain !== undefined) { pos += c.retain; }
      else if (c.insert !== undefined) { newCursor += c.insert.length; pos = pos; }
      else if (c.delete !== undefined) {
        const del = Math.min(c.delete, cursor - pos);
        newCursor -= del; pos += c.delete;
      }
    }
    return Math.max(0, newCursor);
  }

  // ── invert ────────────────────────────────────────────────────────────────

  /** Produce the inverse op (for local undo). Requires the original document string. */
  function invert(op, doc) {
    const result = [];
    let idx = 0;
    for (const c of op) {
      if (c.retain !== undefined) { push(result, { retain: c.retain }); idx += c.retain; }
      else if (c.insert !== undefined) { push(result, { delete: c.insert.length }); }
      else if (c.delete !== undefined) { push(result, { insert: doc.slice(idx, idx + c.delete) }); idx += c.delete; }
    }
    return result;
  }

  // ── fromDiff ──────────────────────────────────────────────────────────────

  /**
   * Build an op from (oldStr, newStr) using a fast single-pass diff
   * that finds the common prefix and suffix, then emits one insert/delete.
   * Suitable for coarse ops (paste, bulk delete); fine ops (single keystrokes)
   * should be constructed directly.
   */
  function fromDiff(oldStr, newStr) {
    let start = 0;
    const minLen = Math.min(oldStr.length, newStr.length);
    while (start < minLen && oldStr[start] === newStr[start]) start++;

    let oldEnd = oldStr.length, newEnd = newStr.length;
    while (oldEnd > start && newEnd > start && oldStr[oldEnd-1] === newStr[newEnd-1]) {
      oldEnd--; newEnd--;
    }

    const op = [];
    if (start > 0)             push(op, { retain: start });
    if (oldEnd > start)        push(op, { delete: oldEnd - start });
    if (newEnd > start)        push(op, { insert: newStr.slice(start, newEnd) });
    if (oldStr.length > oldEnd) push(op, { retain: oldStr.length - oldEnd });
    return op;
  }

  // ── ClientState ───────────────────────────────────────────────────────────

  /**
   * ClientState manages the three-state client OT machine:
   *
   *   SYNC              — no outstanding op, doc matches server
   *   AWAITING_ACK      — sent one op, waiting for server ack
   *   AWAITING_WITH_BUF — have acked op in flight AND local buffer pending
   *
   * Usage:
   *   const cs = new OT.ClientState(sendFn);
   *   cs.applyLocal(op)     — user made a change; may send immediately
   *   cs.ackFromServer(rev) — server acknowledged our op
   *   cs.opFromServer(op, rev) — server broadcast someone else's op
   */
  class ClientState {
    constructor(sendFn) {
      this.send      = sendFn;   // fn(op, revision) — transmit to server
      this.revision  = 0;        // last acknowledged server revision
      this.state     = 'SYNC';
      this.outstanding = null;   // op awaiting ack
      this.buffer      = null;   // local op buffered while waiting for ack
      this.doc         = '';
    }

    init(doc, revision) {
      this.doc      = doc;
      this.revision = revision;
      this.state    = 'SYNC';
      this.outstanding = null;
      this.buffer      = null;
    }

    /** User typed; build op from diff and schedule send */
    localChange(newDoc) {
      const op = fromDiff(this.doc, newDoc);
      if (!op.length) return;
      this.doc = newDoc;
      this._applyLocal(op);
    }

    _applyLocal(op) {
      switch (this.state) {
        case 'SYNC':
          this.outstanding = op;
          this.state = 'AWAITING_ACK';
          this.send(op, this.revision);
          break;
        case 'AWAITING_ACK':
          this.buffer = this.buffer ? compose(this.buffer, op) : op;
          this.state  = 'AWAITING_WITH_BUF';
          break;
        case 'AWAITING_WITH_BUF':
          this.buffer = compose(this.buffer, op);
          break;
      }
    }

    /** Server acknowledged our outstanding op; revision advances */
    ackFromServer() {
      if (this.state === 'SYNC') return; // stale ack
      this.revision++;
      switch (this.state) {
        case 'AWAITING_ACK':
          this.outstanding = null;
          this.state = 'SYNC';
          break;
        case 'AWAITING_WITH_BUF':
          this.outstanding = this.buffer;
          this.buffer      = null;
          this.state       = 'AWAITING_ACK';
          this.send(this.outstanding, this.revision);
          break;
      }
    }

    /**
     * Server broadcast op from another client.
     * Returns the transformed op to apply to the local document.
     */
    opFromServer(serverOp) {
      this.revision++;
      let transformed = serverOp;

      switch (this.state) {
        case 'SYNC':
          break;
        case 'AWAITING_ACK': {
          const [prime, outstandingPrime] = transform(serverOp, this.outstanding, 'left');
          this.outstanding = outstandingPrime;
          transformed = prime;
          break;
        }
        case 'AWAITING_WITH_BUF': {
          const [prime1, outstandingPrime] = transform(serverOp, this.outstanding, 'left');
          const [prime2, bufferPrime]      = transform(prime1, this.buffer, 'left');
          this.outstanding = outstandingPrime;
          this.buffer      = bufferPrime;
          transformed = prime2;
          break;
        }
      }

      try {
        this.doc = apply(this.doc, transformed);
      } catch (e) {
        console.error('[OT] apply error — requesting full resync', e);
        return null; // caller should request full document reload
      }
      return transformed;
    }

    /** Build undo op for the last local change (must track doc before change) */
    undoOp(opToUndo, docBefore) {
      return invert(opToUndo, docBefore);
    }
  }

  // ── Public API ────────────────────────────────────────────────────────────

  return { apply, compose, transform, transformCursor, invert, fromDiff, ClientState,
           baseLength, targetLength };
})();

window.OT = OT;
