package lex.book 
{
	import lex.geom.V2;
	import lex.book.BookStyle;
	/**
	 * ...
	 * @author Alex Lexcuk http://www.murmadillo.tut.su
	 */
	public class FixMouse
	{
		private var pageP:V2 = new V2();
		private var s:BookStyle;
		private var v:V2;
		private var diagonal:Number;
		public function FixMouse(bs:BookStyle) 
		{
			s = bs;
			v = new V2();
			diagonal = Math.sqrt(s.width * s.width + s.height * s.height);
		}

		public function fix(p:V2):V2 {
			pageP.copy(p);
			fixDistTo(s.midBottom, s.width);
			pageP.copy(v);
			fixDistTo(s.midTop, diagonal);
			return v;
		}

		private function fixDistTo(start:V2, dist:Number):void {
			v.copy(pageP);
			v.minus(start);
			if (v.length() > dist) {//если больше dist нормализируем dist-ом
				v.normalize();
				v.scale(dist)
			}
			v.plus(start);
		}

	}

}

