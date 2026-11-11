"""
Descarga las fotos de producto del catálogo de demostración de LaU Marketplace.

Lee catalog.json (la misma fuente que usa MarketplaceCatalogSeeder), busca cada imagen en
Wikimedia Commons (y, si no hay resultado, en Openverse), se queda solo con licencias que
permiten reutilizar (CC0, dominio público, CC BY, CC BY-SA) y guarda miniaturas JPEG de
~800 px en productos/<clave>.jpg. Escribe credits.json y CREDITS.md con autor, licencia y
fuente de cada foto.

Uso (siempre con -I, desde cualquier carpeta):
    python -I database/seeders/catalog/fetch_images.py                 # descarga lo que falte
    python -I database/seeders/catalog/fetch_images.py --only pupusas,horchata --force
    python -I database/seeders/catalog/fetch_images.py --candidates pupusas --preview-dir C:/tmp/prev

Para cambiar una foto mala: fijar el archivo exacto en catalog.json, por ejemplo
    "pupusas": { "query": [...], "file": "File:Pupusas.jpg" }
o, de Openverse, "openverse_id": "<uuid>". Luego correr con --only <clave> --force.

Los archivos descargados son datos: solo se abren con Pillow para redimensionarlos.
"""

from __future__ import annotations

import argparse
import html
import io
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

try:
    from PIL import Image
except ImportError:  # Sin Pillow se guarda la miniatura tal como la sirve Commons.
    Image = None

HERE = Path(__file__).resolve().parent
CATALOG = HERE / "catalog.json"
OUT_DIR = HERE / "productos"
CREDITS_JSON = HERE / "credits.json"
CREDITS_MD = HERE / "CREDITS.md"

USER_AGENT = "LaU-catalog-seeder/1.0 (https://lauapp.es)"
COMMONS_API = "https://commons.wikimedia.org/w/api.php"
OPENVERSE_API = "https://api.openverse.org/v1/images/"

THUMB_WIDTH = 800
MAX_BYTES = 200 * 1024
MIN_WIDTH = 400

# Palabras en el título que casi siempre indican algo que no es una foto de producto.
BAD_TITLE = re.compile(
    r"logo|icon|screenshot|captura|diagram|map\b|mapa|chart\b|graph|drawing\b|svg|"
    r"stamp|banknote|coat of arms|flag|poster of|advert|portrait|selfie",
    re.IGNORECASE,
)


def http_get(url: str, params: dict | None = None, retries: int = 3) -> bytes:
    if params:
        url = url + "?" + urllib.parse.urlencode(params)
    last = None
    for attempt in range(retries):
        req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
        try:
            with urllib.request.urlopen(req, timeout=40) as resp:
                return resp.read()
        except urllib.error.HTTPError as exc:  # 429: esperar y reintentar
            last = exc
            if exc.code in (429, 500, 502, 503, 504):
                time.sleep(3 * (attempt + 1))
                continue
            raise
        except urllib.error.URLError as exc:
            last = exc
            time.sleep(2 * (attempt + 1))
    raise last  # type: ignore[misc]


def strip_html(value: str | None) -> str:
    text = re.sub(r"<[^>]+>", " ", value or "")
    text = re.sub(r"\s+", " ", html.unescape(text)).strip()
    return text[:160]


def license_ok(code: str, short: str) -> bool:
    """CC0, dominio público, CC BY y CC BY-SA. Nada con NC/ND ni GFDL sola."""
    code, short = (code or "").lower(), (short or "").lower()
    joined = f"{code} {short}"
    if "-nc" in joined or "-nd" in joined or " nc" in joined or " nd" in joined:
        return False
    return (
        code.startswith(("cc0", "pd", "cc-by-", "cc-by-sa-"))
        or short.startswith(("cc0", "public domain", "cc by ", "cc by-sa ", "cc-by-", "pdm"))
        or short in ("cc by", "cc by-sa", "public domain")
    )


# ---------------------------------------------------------------- Wikimedia Commons

COMMONS_PROPS = {
    "action": "query",
    "format": "json",
    "formatversion": "2",
    "prop": "imageinfo",
    "iiprop": "url|extmetadata|mime|size",
    "iiurlwidth": str(THUMB_WIDTH),
    "iiextmetadatafilter": "LicenseShortName|License|Artist|ObjectName|Restrictions",
}


def commons_candidates(query: str, limit: int = 25) -> list[dict]:
    params = dict(COMMONS_PROPS, generator="search", gsrnamespace="6",
                  gsrsearch=f"{query} filetype:bitmap", gsrlimit=str(limit))
    data = json.loads(http_get(COMMONS_API, params))
    pages = sorted(data.get("query", {}).get("pages", []), key=lambda p: p.get("index", 0))
    return [c for c in (commons_record(p) for p in pages) if c]


def commons_file(title: str) -> dict | None:
    data = json.loads(http_get(COMMONS_API, dict(COMMONS_PROPS, titles=title)))
    pages = data.get("query", {}).get("pages", [])
    return commons_record(pages[0], strict=False) if pages else None


def commons_record(page: dict, strict: bool = True) -> dict | None:
    info = (page.get("imageinfo") or [None])[0]
    if not info:
        return None
    meta = info.get("extmetadata", {})
    get = lambda k: (meta.get(k) or {}).get("value", "")
    title = page.get("title", "")
    if info.get("mime") not in ("image/jpeg", "image/png"):
        return None
    if not license_ok(get("License"), get("LicenseShortName")):
        return None
    if strict:
        if (info.get("width") or 0) < MIN_WIDTH or BAD_TITLE.search(title):
            return None
        if "personality" in get("Restrictions").lower():  # fotos con personas identificables
            return None
    return {
        "provider": "Wikimedia Commons",
        "title": title.removeprefix("File:"),
        "author": strip_html(get("Artist")) or "Autor desconocido",
        "license": strip_html(get("LicenseShortName")) or get("License"),
        "source_url": info.get("descriptionurl"),
        "image_url": info.get("thumburl") or info.get("url"),
    }


# ---------------------------------------------------------------- Openverse (segunda fuente)

def openverse_candidates(query: str, limit: int = 20) -> list[dict]:
    params = {"q": query, "license_type": "commercial", "license": "by,by-sa,cc0,pdm",
              "page_size": str(limit), "mature": "false"}
    data = json.loads(http_get(OPENVERSE_API, params))
    return [r for r in (openverse_record(x) for x in data.get("results", [])) if r]


def openverse_by_id(identifier: str) -> dict | None:
    return openverse_record(json.loads(http_get(f"{OPENVERSE_API}{identifier}/")))


def openverse_record(item: dict) -> dict | None:
    lic = (item.get("license") or "").lower()
    if lic not in ("by", "by-sa", "cc0", "pdm"):
        return None
    if BAD_TITLE.search(item.get("title") or ""):
        return None
    label = {"cc0": "CC0", "pdm": "Public Domain Mark"}.get(lic, f"CC {lic.upper()} {item.get('license_version') or ''}".strip())
    return {
        "provider": f"Openverse ({item.get('provider') or item.get('source')})",
        "title": item.get("title") or item.get("id"),
        "author": item.get("creator") or "Autor desconocido",
        "license": label,
        "source_url": item.get("foreign_landing_url"),
        "image_url": item.get("url"),
        "openverse_id": item.get("id"),
    }


# ---------------------------------------------------------------- Descarga y miniatura

def to_jpeg(raw: bytes) -> bytes:
    if Image is None:
        return raw
    with Image.open(io.BytesIO(raw)) as img:
        img.load()
        if img.mode in ("RGBA", "LA", "P"):
            img = img.convert("RGBA")
            bg = Image.new("RGB", img.size, (255, 255, 255))
            bg.paste(img, mask=img.split()[-1])
            img = bg
        else:
            img = img.convert("RGB")
        img.thumbnail((THUMB_WIDTH, THUMB_WIDTH))
        for quality in (84, 78, 72, 65, 58):
            buf = io.BytesIO()
            img.save(buf, "JPEG", quality=quality, optimize=True, progressive=True)
            if buf.tell() <= MAX_BYTES:
                break
        return buf.getvalue()


def resolve(key: str, spec: dict, skip_titles: set[str]) -> dict | None:
    if spec.get("file"):
        return commons_file(spec["file"])
    if spec.get("openverse_id"):
        return openverse_by_id(spec["openverse_id"])
    queries = spec.get("query") or [key.replace("-", " ")]
    queries = [queries] if isinstance(queries, str) else queries
    if spec.get("source") != "openverse":
        for q in queries:
            for cand in commons_candidates(q):
                if cand["title"] not in skip_titles:
                    return cand
            time.sleep(0.4)
    for q in queries:
        for cand in openverse_candidates(q):
            if cand["title"] not in skip_titles:
                return cand
        time.sleep(0.4)
    return None


def write_credits_md(credits: dict) -> None:
    lines = [
        "# Créditos de las fotos del catálogo de demostración",
        "",
        "Fotos reales descargadas por `fetch_images.py` desde Wikimedia Commons y Openverse, solo con",
        "licencias que permiten reutilizarlas (CC0, dominio público, CC BY, CC BY-SA). Se redujeron a",
        "~800 px y se convirtieron a JPEG; no se hicieron otros cambios. Los logos de `logos/` son",
        "originales de LaU para negocios ficticios.",
        "",
        "| Archivo | Título | Autor | Licencia | Fuente |",
        "|---|---|---|---|---|",
    ]
    esc = lambda s: str(s or "").replace("|", "\\|")
    for key in sorted(credits):
        c = credits[key]
        lines.append(f"| `productos/{c['file']}` | {esc(c['title'])} | {esc(c['author'])} | {esc(c['license'])} | "
                     f"[{esc(c['provider'])}]({c['source_url']}) |")
    CREDITS_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--only", help="claves separadas por coma")
    parser.add_argument("--force", action="store_true", help="volver a descargar aunque exista")
    parser.add_argument("--candidates", help="clave: lista candidatas en vez de descargar")
    parser.add_argument("--preview-dir", help="con --candidates, guarda miniaturas para revisarlas")
    parser.add_argument("--limit", type=int, default=8)
    parser.add_argument("--query", help="con --candidates, búsqueda distinta a la del catálogo")
    parser.add_argument("--source", choices=["commons", "openverse", "all"], default="commons")
    args = parser.parse_args()
    # Con -I se ignora PYTHONIOENCODING: en Windows la consola redirigida queda en cp1252.
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(encoding="utf-8", errors="replace")

    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    images: dict = catalog["images"]

    if args.candidates:
        preview = Path(args.preview_dir) if args.preview_dir else None
        index_file = preview / "candidates.json" if preview else None
        if preview:
            preview.mkdir(parents=True, exist_ok=True)
        index = json.loads(index_file.read_text(encoding="utf-8")) if index_file and index_file.exists() else {}
        for key in args.candidates.split(","):
            spec = images.get(key, {"query": [key]})
            queries = [args.query] if args.query else (spec.get("query") or [key])
            queries = [queries] if isinstance(queries, str) else queries
            found, seen = [], set()
            for q in queries:
                if args.source in ("commons", "all"):
                    found += commons_candidates(q, 40)
                if args.source in ("openverse", "all"):
                    try:
                        found += openverse_candidates(q)
                    except Exception as exc:  # Openverse a veces limita sin clave; no es fatal
                        print(f"  (openverse: {exc})")
            found = [c for c in found if not (c["title"] in seen or seen.add(c["title"]))][: args.limit]
            index[key] = [{k: c.get(k) for k in ("provider", "title", "license", "openverse_id")} for c in found]
            print(f"== {key}")
            for i, c in enumerate(found):
                print(f"[{i}] {c['provider']} | {c['title']} | {c['license']}")
                if preview:
                    try:
                        (preview / f"{key}~{i}.jpg").write_bytes(to_jpeg(http_get(c["image_url"])))
                    except Exception as exc:
                        print(f"     no se pudo bajar: {exc}")
                    time.sleep(0.2)
        if index_file:
            index_file.write_text(json.dumps(index, ensure_ascii=False, indent=2), encoding="utf-8")
        return 0

    OUT_DIR.mkdir(exist_ok=True)
    credits = json.loads(CREDITS_JSON.read_text(encoding="utf-8")) if CREDITS_JSON.exists() else {}
    wanted = set(args.only.split(",")) if args.only else set(images)
    unknown = wanted - set(images)
    if unknown:
        print("Claves que no están en catalog.json:", ", ".join(sorted(unknown)))
        return 1

    used_titles = {c["title"] for k, c in credits.items() if k not in wanted}
    missing = []
    for key in sorted(wanted):
        target = OUT_DIR / f"{key}.jpg"
        if target.exists() and key in credits and not args.force:
            continue
        try:
            cand = resolve(key, images[key], used_titles)
            if not cand:
                missing.append(key)
                print(f"  ✗ {key}: sin resultado con licencia libre")
                continue
            data = to_jpeg(http_get(cand["image_url"]))
            target.write_bytes(data)
            used_titles.add(cand["title"])
            credits[key] = {k: v for k, v in cand.items() if k != "image_url"} | {"file": target.name}
            print(f"  ✓ {key}: {cand['title']} ({cand['license']}, {len(data) // 1024} KB)")
        except Exception as exc:
            missing.append(key)
            print(f"  ✗ {key}: {exc}")
        time.sleep(0.5)

    # Créditos solo de las fotos que siguen en el catálogo y en disco.
    credits = {k: v for k, v in credits.items() if k in images and (OUT_DIR / v["file"]).exists()}
    CREDITS_JSON.write_text(json.dumps(credits, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    write_credits_md(credits)
    print(f"\n{len(credits)} fotos con créditos. Sin foto: {', '.join(missing) or 'ninguna'}")
    return 0 if not missing else 2


if __name__ == "__main__":
    sys.exit(main())
