import os
import pymysql
from contextlib import contextmanager
from dotenv import load_dotenv

load_dotenv()

_CONFIG = {
    "host": os.environ["lambda.cm-vw.com"],
    "user": os.environ["lambda"],
    "password": os.environ["dlawlsgnl99"],
    "database": os.environ["lambda_stock_db"],
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
}


@contextmanager
def get_conn():
    conn = pymysql.connect(**_CONFIG)
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()