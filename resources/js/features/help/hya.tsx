import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Shell } from '@/components/shell';
import { Avatar, Icon, Notice, RowLink } from '@/components/ui';
import { isActive, splitRoom } from '@/lib/live';
import type { Visit } from '@/types';

interface Message {
  id: number;
  role: 'user' | 'assistant';
  text: string;
  /** Written by Gemini from the server's facts (false: fixed rule or error text). */
  ai?: boolean;
  /** Connection error shown locally; never sent back as history. */
  failed?: boolean;
}
/** Local clock, e.g. "09.10", for the day stamp above the conversation. */
const clock = () => new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
interface Reply {
  reply: string;
  ai?: boolean;
}
/** How many earlier messages go with a question, so follow-ups keep their context. */
const HISTORY = 6;
/** Hya's replies come from Laravel so they can use the patient's live queue data. */
async function askHya(question: string, history: Message[]): Promise<Reply> {
  const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
  const response = await fetch('/hya', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': token,
    },
    body: JSON.stringify({
      question,
      history: history
        .filter((message) => !message.failed)
        .slice(-HISTORY)
        .map(({ role, text }) => ({ role, text })),
    }),
  });
  if (!response.ok) throw new Error(`Hya ${response.status}`);
  return response.json();
}
function summary(visit: Visit) {
  return {
    booked: 'Belum check-in',
    waiting: `${visit.ahead} pasien lagi`,
    serving: 'Giliran Anda',
    laboratory: 'Layanan laboratorium',
    pharmacy: 'Penyiapan obat',
    completed: '',
    no_show: '',
    cancelled: '',
  }[visit.stage];
}
export function Hya() {
  const { visit, aiGuide } = usePage<{ aiGuide?: boolean }>().props;
  const [input, setInput] = useState('');
  const [messages, setMessages] = useState<Message[]>([]);
  const [pending, setPending] = useState(false);
  const [startedAt, setStartedAt] = useState('');
  const last = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (messages.length) last.current?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
  }, [messages, pending]);
  async function ask(question: string) {
    const value = question.trim().slice(0, 500);
    if (!value || pending) return;
    const say = (message: Omit<Message, 'id'>) =>
      setMessages((current) => [...current, { ...message, id: current.length }]);
    const history = messages;
    if (!history.length) setStartedAt(clock());
    say({ role: 'user', text: value });
    setInput('');
    setPending(true);
    try {
      const { reply, ai } = await askHya(value, history);
      say({ role: 'assistant', text: reply, ai });
    } catch {
      say({
        role: 'assistant',
        text: 'Hya belum dapat menjawab. Periksa koneksi Anda, lalu coba lagi.',
        failed: true,
      });
    } finally {
      setPending(false);
    }
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    ask(input);
  }
  return (
    <Shell title="Hya" subtitle="Asisten digital HyTrack" back="/kunjungan" hya>
      {!messages.length ? (
        <>
          <p className="eyebrow" style={{ color: 'var(--primary)' }}>
            PANDUAN KUNJUNGAN ANDA
          </p>
          <img
            className="hya-illustration"
            src="/assets/hya.png"
            width={400}
            height={300}
            alt="Hya, asisten layanan rumah sakit, melambaikan tangan"
          />
          <section className="intro">
            <h2>Halo, saya Hya.</h2>
            <p>
              Saya bantu Anda mengikuti antrean
              <br />
              dan menemukan layanan berikutnya.
            </p>
          </section>
          {isActive(visit) ? (
            <Notice>
              KUNJUNGAN AKTIF · {visit.ticket}
              <br />
              <strong>
                {summary(visit)} · Poli {visit.clinic}
              </strong>
            </Notice>
          ) : (
            <Notice>
              Belum ada kunjungan aktif. Anda tetap bisa bertanya tentang panduan layanan.
            </Notice>
          )}
          <h3>Apa yang ingin Anda ketahui?</h3>
          <div className="stack">
            {[
              'Kapan giliran saya?',
              'Ke ruangan mana saya harus pergi?',
              'Bagaimana cara check-in?',
            ].map((question) => (
              <button key={question} className="suggestion" onClick={() => ask(question)}>
                {question}
                <Icon name="arrow" />
              </button>
            ))}
          </div>
        </>
      ) : (
        <>
          <div className="chat-context notice">
            {isActive(visit) ? (
              <>
                <span>
                  {visit.ticket} · Poli {visit.clinic}
                </span>
                {visit.syncedAt && <small>Data {visit.syncedAt}</small>}
              </>
            ) : (
              'Panduan layanan'
            )}
          </div>
          <p className="chat-day">Hari ini · {startedAt}</p>
          <div className="messages" role="log" aria-live="polite" aria-label="Percakapan Hya">
            {messages.map((message) => (
              <Bubble key={message.id} role={message.role} ai={message.ai}>
                {message.text}
              </Bubble>
            ))}
            {pending && <Bubble role="assistant">Menyiapkan jawaban…</Bubble>}
          </div>
          <div className="chat-follow" ref={last}>
            {isActive(visit) && visit.room ? (
              <>
                <Place room={visit.room} />
                <small className="chat-source">
                  Sumber: antrean kunjungan{visit.syncedAt && ` · ${visit.syncedAt}`}
                </small>
              </>
            ) : (
              <RowLink href="/petunjuk-ruangan">Buka petunjuk ruangan</RowLink>
            )}
            <button
              className="chat-chip"
              disabled={pending}
              onClick={() => ask('Bagaimana jika saya terlambat?')}
            >
              Bagaimana jika saya terlambat?
            </button>
          </div>
        </>
      )}
      <div className={messages.length ? 'chat-follow' : 'stack'}>
        <Notice tone="warning">
          Untuk keluhan atau pertanyaan medis, silakan hubungi tenaga kesehatan.
        </Notice>
        {messages.length > 0 && (
          <button className="text-button" onClick={() => setMessages([])}>
            Mulai percakapan baru
          </button>
        )}
      </div>
      <div className="chat-composer">
        <form onSubmit={submit}>
          <input
            aria-label="Pertanyaan untuk Hya"
            placeholder="Tulis pertanyaan Anda…"
            maxLength={500}
            value={input}
            onChange={(e) => setInput(e.target.value)}
          />
          <button type="submit" aria-label="Kirim pertanyaan" disabled={!input.trim() || pending}>
            <Icon name="send" />
          </button>
        </form>
        <small>
          {aiGuide
            ? 'Hya dijawab AI (Gemini) dari data antrean Anda dan bisa keliru; pastikan ke petugas. Jangan masukkan data pribadi atau medis.'
            : 'Respons terstruktur, bukan AI. Jangan masukkan data pribadi atau medis.'}
        </small>
      </div>
    </Shell>
  );
}

/** One chat turn (Figma 43:2 frame 21): Hya's avatar and name sit above her bubble. */
function Bubble({ role, ai, children }: { role: Message['role']; ai?: boolean; children: string }) {
  if (role === 'user') return <p className="message user">{children}</p>;
  return (
    <div className="message assistant">
      <strong className="message-from">
        <Avatar />
        Hya{ai && <span className="muted"> · dijawab AI</span>}
      </strong>
      <p>{children}</p>
    </div>
  );
}

/** Where to wait, from the live visit, with the way to get there. */
function Place({ room }: { room: string }) {
  const [name, building] = splitRoom(room);
  return (
    <Link className="chat-place" href="/petunjuk-ruangan">
      <small>TEMPAT MENUNGGU</small>
      <strong>{name}</strong>
      {building && <span>{building}</span>}
      <b>
        Buka petunjuk ruangan
        <Icon name="arrow" />
      </b>
    </Link>
  );
}
