"""JSON stdin/stdout bridge. Source is parsed as text and is never executed."""
import importlib.metadata
import json
import sys
import lizard
from lizard_languages import get_reader_for

ENGINE = "lizard-" + importlib.metadata.version("lizard")

def analyze(payload):
    filename = payload["path"]
    reader = get_reader_for(filename)
    engine = ENGINE
    if reader is None:
        return {"engine": engine, "status": "unsupported", "language": None,
                "reason": "No Lizard reader for this file type", "functions": []}
    result = lizard.analyze_file.analyze_source_code(filename, payload["source"])
    return {"engine": engine, "status": "analyzed", "reason": None,
            "language": "c" if filename.endswith(".c") else reader.language_names[0], "functions": [
                {"name": f.name, "signature": f.long_name, "line": f.start_line,
                 "score": f.cyclomatic_complexity} for f in result.function_list
            ]}

if __name__ == "__main__":
    try:
        payload = json.load(sys.stdin)
        result = [analyze(file) for file in payload["files"]] if "files" in payload else analyze(payload)
        print(json.dumps(result))
    except Exception as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
