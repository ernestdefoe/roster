/** The shapes the server sends that the forum reads: the player profile and the crest wall's data. */

export interface Bio {
  headshot?: string;
  jersey?: string;
  position?: string;
  height?: string;
  weight?: string;
  experience?: string;
  birthPlace?: string;
  age?: number;
  status?: string;
}

export interface StatsTable {
  title: string;
  labels: string[];
  names: string[];
  rows: { label: string; values: string[] }[];
}

export interface GameRow {
  date: string | null;
  atVs: string;
  opponent: string;
  opponentLogo: string | null;
  result: string;
  score: string;
  values: string[];
}

export interface GamesTable {
  labels: string[];
  names: string[];
  rows: GameRow[];
}

export interface Video {
  id: string;
  title: string;
  description: string;
  image: string | null;
  published: string | null;
  url: string | null;
}

export interface NewsItem {
  title: string;
  description: string;
  image: string | null;
  published: string | null;
  url: string;
}

export interface About {
  title: string;
  extract: string;
  url: string;
  image: string | null;
}

export interface Profile {
  bio?: Bio;
  stats?: StatsTable | null;
  games?: GamesTable | null;
  nextGame?: { name: string; date: string | null } | null;
  videos?: Video[];
  news?: NewsItem[];
  espnUrl?: string | null;
  about?: About | null;
  /** True while the queue is still gathering it. */
  pending: boolean;
}

export interface CrestTeam {
  name: string;
  slug: string;
  logo: string;
  logoDark: string;
}

export interface CrestWallData {
  teams?: CrestTeam[];
  counts?: { teams: number; players: number } | null;
}

export interface CrestWallSettings {
  rows?: string | number;
  caption?: string;
}
